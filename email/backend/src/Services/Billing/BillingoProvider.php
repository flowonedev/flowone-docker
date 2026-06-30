<?php

namespace Webmail\Services\Billing;

/**
 * BillingoProvider - Billingo API v3 Integration
 * 
 * Billingo is a popular Hungarian invoicing platform.
 * API Docs: https://app.billingo.hu/api-docs
 * 
 * Authentication: X-API-KEY header
 * Base URL: https://api.billingo.hu/v3
 */
class BillingoProvider implements BillingProviderInterface
{
    private const BASE_URL = 'https://api.billingo.hu/v3';
    private const TIMEOUT = 30;

    private string $apiKey;
    private ?int $blockId;

    // Billingo payment method mapping
    private const PAYMENT_METHODS = [
        'bank_transfer' => 'wire_transfer',
        'cash' => 'cash',
        'card' => 'bankcard',
        'paypal' => 'paypal',
        'other' => 'other',
    ];

    // Billingo currency codes (same as ISO 4217)
    private const CURRENCIES = ['HUF', 'EUR', 'USD', 'GBP', 'CHF', 'CZK', 'PLN', 'RON'];

    // Tax rate mapping for Billingo VAT rates
    private const VAT_CODES = [
        0 => 'AAM',     // Tax-free
        5 => '5%',
        18 => '18%',
        27 => '27%',
    ];

    public function __construct(string $apiKey, ?int $blockId = null)
    {
        $this->apiKey = $apiKey;
        $this->blockId = $blockId;
    }

    public function getProviderName(): string
    {
        return 'billingo';
    }

    // =========================================================================
    // Connection Test
    // =========================================================================

    public function testConnection(): array
    {
        try {
            $response = $this->request('GET', '/document-blocks?type=invoice');

            if (!$response['success']) {
                return [
                    'success' => false,
                    'message' => $response['error'] ?? 'Failed to connect to Billingo',
                ];
            }

            $blocks = $response['data'] ?? [];

            return [
                'success' => true,
                'message' => 'Connected to Billingo successfully',
                'data' => [
                    'invoice_blocks' => array_map(fn($b) => [
                        'id' => $b['id'],
                        'name' => $b['name'] ?? $b['prefix'] ?? 'Block ' . $b['id'],
                        'prefix' => $b['prefix'] ?? '',
                    ], $blocks),
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Billingo connection error: ' . $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // Invoice Creation
    // =========================================================================

    public function createInvoice(array $invoiceData): array
    {
        try {
            // Step 1: Create or find the partner (client) in Billingo
            $partner = $this->findOrCreatePartner($invoiceData);
            if (isset($partner['error'])) {
                return ['success' => false, 'error' => $partner['error']];
            }

            // Step 2: Determine the block to use
            $blockId = $this->blockId ? (int)$this->blockId : null;
            if (empty($blockId)) {
                $blocksResult = $this->getInvoiceBlocks();
                if ($blocksResult['success'] && !empty($blocksResult['blocks'])) {
                    $blockId = $blocksResult['blocks'][0]['id'];
                } else {
                    return ['success' => false, 'error' => 'No invoice block found in Billingo. Please configure one.'];
                }
            }

            // Step 3: Build invoice items
            $items = [];
            foreach ($invoiceData['items'] ?? [] as $item) {
                $taxRate = (float)($item['tax_rate'] ?? $invoiceData['tax_rate'] ?? 27);
                $vatCode = $this->mapVatCode($taxRate);

                $items[] = [
                    'name' => $item['description'] ?? 'Service',
                    'unit_price' => (float)($item['unit_price'] ?? 0),
                    'unit_price_type' => 'net', // net price, tax calculated on top
                    'quantity' => (float)($item['quantity'] ?? 1),
                    'unit' => !empty($item['unit']) ? $item['unit'] : 'db',
                    'vat' => $vatCode,
                ];
            }

            if (empty($items)) {
                return ['success' => false, 'error' => 'Invoice must have at least one line item'];
            }

            // Step 4: Build the invoice payload
            $payload = [
                'partner_id' => $partner['id'],
                'block_id' => $blockId,
                'type' => 'invoice',
                'fulfillment_date' => $invoiceData['issue_date'] ?? date('Y-m-d'),
                'due_date' => $invoiceData['due_date'] ?? date('Y-m-d', strtotime('+8 days')),
                'payment_method' => self::PAYMENT_METHODS[$invoiceData['payment_method'] ?? 'bank_transfer'] ?? 'wire_transfer',
                'language' => $this->mapLanguage($invoiceData['language'] ?? 'hu'),
                'currency' => $invoiceData['currency'] ?? 'HUF',
                'items' => $items,
                'electronic' => (bool)($invoiceData['electronic'] ?? true),
            ];

            if (!empty($invoiceData['notes'])) {
                $payload['comment'] = $invoiceData['notes'];
            }

            // Round exchange rate for non-HUF currencies (Billingo requires it)
            if ($payload['currency'] !== 'HUF') {
                $payload['conversion_rate'] = 1; // Will use Billingo's daily rate
            }

            // Step 5: Create the invoice
            $response = $this->request('POST', '/documents', $payload);

            if (!$response['success']) {
                return [
                    'success' => false,
                    'error' => 'Billingo: ' . ($response['error'] ?? 'Failed to create invoice'),
                ];
            }

            $doc = $response['data'] ?? [];

            return [
                'success' => true,
                'external_id' => (string)($doc['id'] ?? ''),
                'external_url' => 'https://app.billingo.hu/document/' . ($doc['id'] ?? ''),
                'external_pdf_url' => self::BASE_URL . '/documents/' . ($doc['id'] ?? '') . '/download',
                'invoice_number' => $doc['invoice_number'] ?? $doc['id'] ?? '',
                'message' => 'Invoice created on Billingo',
            ];
        } catch (\Throwable $e) {
            error_log('BillingoProvider::createInvoice error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Billingo error: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // PDF Download
    // =========================================================================

    public function downloadPdf(string $externalId): array
    {
        try {
            // Billingo returns 202 while PDF is being generated, retry with backoff
            $maxRetries = 5;
            $retryDelay = 2; // seconds

            for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL => self::BASE_URL . '/documents/' . $externalId . '/download',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => self::TIMEOUT,
                    CURLOPT_HTTPHEADER => [
                        'X-API-KEY: ' . $this->apiKey,
                        'Accept: application/pdf',
                    ],
                ]);

                $pdfContent = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                curl_close($ch);

                if ($error) {
                    return ['success' => false, 'error' => 'Download failed: ' . $error];
                }

                // 202 = PDF still generating, wait and retry
                if ($httpCode === 202) {
                    if ($attempt < $maxRetries) {
                        sleep($retryDelay);
                        $retryDelay = min($retryDelay * 2, 10); // backoff: 2, 4, 8, 10
                        continue;
                    }
                    return ['success' => false, 'error' => 'PDF still generating after ' . $maxRetries . ' attempts'];
                }

                if ($httpCode !== 200 || empty($pdfContent)) {
                    return ['success' => false, 'error' => 'Failed to download PDF (HTTP ' . $httpCode . ')'];
                }

                // Verify it's actually a PDF
                if (substr($pdfContent, 0, 4) !== '%PDF') {
                    return ['success' => false, 'error' => 'Received response is not a valid PDF'];
                }

                return [
                    'success' => true,
                    'pdf_content' => $pdfContent,
                    'filename' => 'invoice_' . $externalId . '.pdf',
                ];
            }

            return ['success' => false, 'error' => 'PDF download failed after retries'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'PDF download error: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // Status Check
    // =========================================================================

    public function getStatus(string $externalId): array
    {
        try {
            $response = $this->request('GET', '/documents/' . $externalId);

            if (!$response['success']) {
                return ['success' => false, 'error' => $response['error'] ?? 'Failed to get status'];
            }

            $doc = $response['data'] ?? [];
            $paidAmount = (float)($doc['paid_amount'] ?? 0);

            // Map Billingo payment status
            $status = match ($doc['payment_status'] ?? '') {
                'paid' => 'paid',
                'outstanding' => 'sent',
                'overdue' => 'overdue',
                'partially_paid' => 'partial',
                default => 'sent',
            };

            return [
                'success' => true,
                'status' => $status,
                'paid_amount' => $paidAmount,
                'external_status' => $doc['payment_status'] ?? 'unknown',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Status check error: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // Cancel/Storno
    // =========================================================================

    public function cancelInvoice(string $externalId): array
    {
        try {
            $response = $this->request('POST', '/documents/' . $externalId . '/cancel');

            if (!$response['success']) {
                return ['success' => false, 'error' => $response['error'] ?? 'Failed to cancel invoice'];
            }

            return [
                'success' => true,
                'message' => 'Invoice cancelled on Billingo',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Cancel error: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // Invoice Blocks
    // =========================================================================

    public function getInvoiceBlocks(): array
    {
        try {
            $response = $this->request('GET', '/document-blocks?type=invoice');

            if (!$response['success']) {
                return ['success' => false, 'error' => $response['error'] ?? 'Failed to get blocks'];
            }

            $blocks = array_map(fn($b) => [
                'id' => $b['id'],
                'name' => $b['name'] ?? $b['prefix'] ?? 'Block ' . $b['id'],
                'prefix' => $b['prefix'] ?? '',
            ], $response['data'] ?? []);

            return ['success' => true, 'blocks' => $blocks];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Error: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // Partner (Client) Management
    // =========================================================================

    /**
     * Find or create a partner in Billingo for the client
     */
    private function findOrCreatePartner(array $invoiceData): array
    {
        $name = $invoiceData['client_billing_name'] ?? $invoiceData['client_name'] ?? 'Unknown Client';
        $taxNumber = $invoiceData['client_tax_number'] ?? null;
        $email = $invoiceData['client_email'] ?? null;

        // Search for existing partner by tax number first, then by name
        if ($taxNumber) {
            $search = $this->request('GET', '/partners?query=' . urlencode($taxNumber));
            if ($search['success'] && !empty($search['data'])) {
                return $search['data'][0];
            }
        }

        // Also search by name to avoid duplicates
        $search = $this->request('GET', '/partners?query=' . urlencode($name));
        if ($search['success'] && !empty($search['data'])) {
            return $search['data'][0];
        }

        // Create new partner - Billingo requires min 2 chars for city/address, valid post_code
        $partnerPayload = [
            'name' => $name,
            'address' => [
                'country_code' => $invoiceData['client_country'] ?? 'HU',
                'post_code' => $invoiceData['client_zip'] ?: '0000',
                'city' => (strlen($invoiceData['client_city'] ?? '') >= 2) ? $invoiceData['client_city'] : 'N/A',
                'address' => (strlen($invoiceData['client_address'] ?? '') >= 2) ? $invoiceData['client_address'] : 'N/A',
            ],
        ];

        if ($taxNumber) {
            $partnerPayload['taxcode'] = $taxNumber;
        }
        if ($email) {
            $partnerPayload['emails'] = [$email];
        }

        $response = $this->request('POST', '/partners', $partnerPayload);

        if (!$response['success']) {
            return ['error' => 'Failed to create partner in Billingo: ' . ($response['error'] ?? 'Unknown error')];
        }

        return $response['data'] ?? [];
    }

    // =========================================================================
    // HTTP Client
    // =========================================================================

    /**
     * Make an API request to Billingo
     */
    private function request(string $method, string $endpoint, ?array $body = null): array
    {
        $ch = curl_init();
        $url = self::BASE_URL . $endpoint;

        $headers = [
            'X-API-KEY: ' . $this->apiKey,
            'Accept: application/json',
        ];

        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($method === 'POST' || $method === 'PUT') {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $headers;

            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            }

            if ($method === 'POST') {
                $opts[CURLOPT_POST] = true;
            } else {
                $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
            }
        } elseif ($method === 'DELETE') {
            $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        curl_setopt_array($ch, $opts);

        $responseBody = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return ['success' => false, 'error' => 'cURL error: ' . $curlError];
        }

        $data = json_decode($responseBody, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            // Billingo v3 may return data directly or in a 'data' wrapper
            return ['success' => true, 'data' => $data['data'] ?? $data ?? []];
        }

        $errorMessage = $data['message'] ?? $data['error'] ?? $responseBody ?? 'Unknown error';
        // Billingo v3 may return validation errors as arrays/objects - flatten to string
        if (is_array($errorMessage)) {
            $errorMessage = json_encode($errorMessage, JSON_UNESCAPED_UNICODE);
        }
        // Log full details including validation errors and request payload
        $logDetails = "BillingoProvider API error [{$httpCode}]: {$method} {$endpoint} - {$errorMessage}";
        if (!empty($data['errors'])) {
            $logDetails .= " | Validation errors: " . json_encode($data['errors'], JSON_UNESCAPED_UNICODE);
        }
        $logDetails .= " | Full response: " . ($responseBody ?? 'empty');
        if ($body) {
            $logDetails .= " | Request payload: " . json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        error_log($logDetails);

        return ['success' => false, 'error' => $errorMessage, 'http_code' => $httpCode];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Map a numeric tax rate to Billingo VAT code
     */
    private function mapVatCode(float $taxRate): string
    {
        $rate = (int)round($taxRate);

        // Direct match
        if (isset(self::VAT_CODES[$rate])) {
            return self::VAT_CODES[$rate];
        }

        // Default: use percentage string format
        return $rate . '%';
    }

    /**
     * Map language code to Billingo format
     */
    private function mapLanguage(string $lang): string
    {
        return match ($lang) {
            'hu' => 'hu',
            'en' => 'en',
            'de' => 'de',
            default => 'hu',
        };
    }
}

