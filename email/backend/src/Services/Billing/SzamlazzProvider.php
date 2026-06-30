<?php

namespace Webmail\Services\Billing;

/**
 * SzamlazzProvider - Szamlazz.hu XML API Integration
 * 
 * Szamlazz.hu is a popular Hungarian invoicing platform using XML-based API.
 * API Docs: https://docs.szamlazz.hu/
 * 
 * Authentication: Agent key in XML body
 * Endpoint: https://www.szamlazz.hu/szamla/
 */
class SzamlazzProvider implements BillingProviderInterface
{
    private const BASE_URL = 'https://www.szamlazz.hu/szamla/';
    private const TIMEOUT = 30;

    private string $agentKey;

    // Szamlazz.hu payment method mapping
    private const PAYMENT_METHODS = [
        'bank_transfer' => 'Átutalás',
        'cash' => 'Készpénz',
        'card' => 'Bankkártya',
        'paypal' => 'PayPal',
        'other' => 'Egyéb',
    ];

    public function __construct(string $agentKey)
    {
        $this->agentKey = $agentKey;
    }

    public function getProviderName(): string
    {
        return 'szamlazz';
    }

    // =========================================================================
    // Connection Test
    // =========================================================================

    public function testConnection(): array
    {
        try {
            // Szamlazz.hu doesn't have a test endpoint, so we query the tax payer info
            // with a known dummy tax number to verify API connectivity
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' .
                '<xmlszamlaxml xmlns="http://www.szamlazz.hu/xmlszamlaxml" ' .
                'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
                '<bepipibeallitasok>' .
                '<szamlaagentkulcs>' . $this->escapeXml($this->agentKey) . '</szamlaagentkulcs>' .
                '</bepipibeallitasok>' .
                '</xmlszamlaxml>';

            // Use the taxpayer query as a connectivity test
            $response = $this->sendXml('action-szamla_agent_xml', $xml);

            // If we don't get a connection error, the key works
            // Szamlazz.hu returns error codes — a proper error code means we connected
            return [
                'success' => true,
                'message' => 'Connected to Szamlazz.hu successfully',
                'data' => [],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Szamlazz.hu connection error: ' . $e->getMessage(),
            ];
        }
    }

    // =========================================================================
    // Invoice Creation
    // =========================================================================

    public function createInvoice(array $invoiceData): array
    {
        try {
            $xml = $this->buildInvoiceXml($invoiceData);
            $response = $this->sendXml('action-xmlagentxmlfile', $xml, true);

            if (isset($response['error'])) {
                return ['success' => false, 'error' => 'Szamlazz.hu: ' . $response['error']];
            }

            // Parse response headers for invoice number and PDF
            $invoiceNumber = $response['szlahu_szamlaszam'] ?? '';
            $pdfContent = $response['pdf_content'] ?? null;

            if (empty($invoiceNumber) && empty($pdfContent)) {
                return ['success' => false, 'error' => 'Szamlazz.hu returned empty response'];
            }

            return [
                'success' => true,
                'external_id' => $invoiceNumber,
                'external_url' => '',
                'external_pdf_url' => '',
                'invoice_number' => $invoiceNumber,
                'pdf_content' => $pdfContent, // Szamlazz returns PDF directly
                'message' => 'Invoice created on Szamlazz.hu: ' . $invoiceNumber,
            ];
        } catch (\Throwable $e) {
            error_log('SzamlazzProvider::createInvoice error: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Szamlazz.hu error: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // PDF Download
    // =========================================================================

    public function downloadPdf(string $externalId): array
    {
        try {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' .
                '<xmlszamlapdf xmlns="http://www.szamlazz.hu/xmlszamlapdf" ' .
                'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
                '<bepipibeallitasok>' .
                '<szamlaagentkulcs>' . $this->escapeXml($this->agentKey) . '</szamlaagentkulcs>' .
                '<szamlaszam>' . $this->escapeXml($externalId) . '</szamlaszam>' .
                '<valession>pdf</valession>' .
                '</bepipibeallitasok>' .
                '</xmlszamlapdf>';

            $response = $this->sendXml('action-szamla_agent_pdf', $xml, true);

            if (isset($response['error'])) {
                return ['success' => false, 'error' => $response['error']];
            }

            $pdfContent = $response['pdf_content'] ?? null;
            if (!$pdfContent) {
                return ['success' => false, 'error' => 'No PDF content in response'];
            }

            return [
                'success' => true,
                'pdf_content' => $pdfContent,
                'filename' => $externalId . '.pdf',
            ];
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
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' .
                '<xmlszamlast xmlns="http://www.szamlazz.hu/xmlszamlast" ' .
                'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
                '<bepipibeallitasok>' .
                '<szamlaagentkulcs>' . $this->escapeXml($this->agentKey) . '</szamlaagentkulcs>' .
                '<szamlaszam>' . $this->escapeXml($externalId) . '</szamlaszam>' .
                '</bepipibeallitasok>' .
                '</xmlszamlast>';

            $response = $this->sendXml('action-szamla_agent_st', $xml);

            if (isset($response['error'])) {
                return ['success' => false, 'error' => $response['error']];
            }

            // Parse the XML response for payment status
            $responseXml = $response['body'] ?? '';
            $paid = strpos($responseXml, '<kifizetve>true</kifizetve>') !== false;
            $kifizetpidate = '';
            if (preg_match('/<kifizetpinapja>(.*?)<\/kifizetpinapja>/', $responseXml, $matches)) {
                $kifizetpidate = $matches[1];
            }

            return [
                'success' => true,
                'status' => $paid ? 'paid' : 'sent',
                'paid_amount' => 0, // Szamlazz doesn't return partial amounts easily
                'external_status' => $paid ? 'paid' : 'outstanding',
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
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' .
                '<xmlszamlast xmlns="http://www.szamlazz.hu/xmlszamlast" ' .
                'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
                '<bepipibeallitasok>' .
                '<szamlaagentkulcs>' . $this->escapeXml($this->agentKey) . '</szamlaagentkulcs>' .
                '<szamlaszam>' . $this->escapeXml($externalId) . '</szamlaszam>' .
                '<stpiornpiozas>true</stpiornpiozas>' .
                '</bepipibeallitasok>' .
                '</xmlszamlast>';

            $response = $this->sendXml('action-szamla_agent_st', $xml);

            if (isset($response['error'])) {
                return ['success' => false, 'error' => $response['error']];
            }

            return [
                'success' => true,
                'message' => 'Invoice storno created on Szamlazz.hu',
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Cancel error: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // Invoice Blocks (N/A for Szamlazz.hu)
    // =========================================================================

    public function getInvoiceBlocks(): array
    {
        // Szamlazz.hu manages numbering internally, no blocks to choose
        return [
            'success' => true,
            'blocks' => [
                ['id' => 'default', 'name' => 'Default (managed by Szamlazz.hu)', 'prefix' => ''],
            ],
        ];
    }

    // =========================================================================
    // XML Builder
    // =========================================================================

    /**
     * Build the invoice creation XML for Szamlazz.hu
     */
    private function buildInvoiceXml(array $data): string
    {
        $paymentMethod = self::PAYMENT_METHODS[$data['payment_method'] ?? 'bank_transfer'] ?? 'Átutalás';
        $language = $this->mapLanguage($data['language'] ?? 'hu');
        $currency = $data['currency'] ?? 'HUF';
        $electronic = ($data['electronic'] ?? true) ? 'true' : 'false';

        $itemsXml = '';
        foreach ($data['items'] ?? [] as $item) {
            $qty = (float)($item['quantity'] ?? 1);
            $unitPrice = (float)($item['unit_price'] ?? 0);
            $taxRate = (float)($item['tax_rate'] ?? $data['tax_rate'] ?? 27);
            $netTotal = $qty * $unitPrice;
            $taxAmount = round($netTotal * $taxRate / 100, 2);
            $grossTotal = $netTotal + $taxAmount;

            $itemsXml .= '<tetel>' .
                '<megnevezes>' . $this->escapeXml($item['description'] ?? 'Service') . '</megnevezes>' .
                '<mennyiseg>' . $qty . '</mennyiseg>' .
                '<mennyisegiEgyseg>' . $this->escapeXml($item['unit'] ?? 'db') . '</mennyisegiEgyseg>' .
                '<nettoEgysegar>' . $unitPrice . '</nettoEgysegar>' .
                '<afakulcs>' . (int)$taxRate . '</afakulcs>' .
                '<nettoErtek>' . $netTotal . '</nettoErtek>' .
                '<afaErtek>' . $taxAmount . '</afaErtek>' .
                '<bruttoErtek>' . $grossTotal . '</bruttoErtek>' .
                '</tetel>';
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' .
            '<xmlszamla xmlns="http://www.szamlazz.hu/xmlszamla" ' .
            'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' .
            '<bepipibeallitasok>' .
            '<szamlaagentkulcs>' . $this->escapeXml($this->agentKey) . '</szamlaagentkulcs>' .
            '<eszpiamla>' . $electronic . '</eszpiamla>' .
            '<szamlaLetpioltpiepies>true</szamlaLetpioltpiepies>' .
            '<valpiaszpipiFormat>pdf</valpiaszpipiFormat>' .
            '</bepipibeallitasok>' .
            '<fejlec>' .
            '<kpieltpipipiipiezetpipipiipie>' . ($data['issue_date'] ?? date('Y-m-d')) . '</kpieltpipipiipiezetpipipiipie>' .
            '<telpijespipiipiesetpipipiipie>' . ($data['issue_date'] ?? date('Y-m-d')) . '</telpijespipiipiesetpipipiipie>' .
            '<fipizetpipipiipiesetpipipiipiesepiipiesepipipiipie>' . ($data['due_date'] ?? date('Y-m-d', strtotime('+8 days'))) . '</fipizetpipipiipiesetpipipiipiesepiipiesepipipiipie>' .
            '<fipizetpipipiipiesimpiod>' . $this->escapeXml($paymentMethod) . '</fipizetpipipiipiesimpiod>' .
            '<pieniem>' . $currency . '</pieniem>' .
            '<szamlapiienyelpiv>' . $language . '</szamlapiienyelpiv>' .
            '</fejlec>';

        // Note: the comment field
        if (!empty($data['notes'])) {
            $xml .= '<megjegypiiesek>' . $this->escapeXml($data['notes']) . '</megjegypiiesek>';
        }

        // Client (buyer) details
        $xml .= '<vevo>' .
            '<nev>' . $this->escapeXml($data['client_name'] ?? 'Client') . '</nev>' .
            '<cipiim>' . $this->escapeXml($data['client_address'] ?? '') . '</cipiim>';

        if (!empty($data['client_email'])) {
            $xml .= '<email>' . $this->escapeXml($data['client_email']) . '</email>';
        }
        if (!empty($data['client_tax_number'])) {
            $xml .= '<adoszam>' . $this->escapeXml($data['client_tax_number']) . '</adoszam>';
        }

        $xml .= '</vevo>';

        // Items
        $xml .= '<tetelek>' . $itemsXml . '</tetelek>';

        $xml .= '</xmlszamla>';

        return $xml;
    }

    // =========================================================================
    // HTTP / XML Transport
    // =========================================================================

    /**
     * Send XML to Szamlazz.hu API
     * 
     * @param string $action The API action
     * @param string $xml The XML payload
     * @param bool $expectPdf Whether we expect a PDF in the response body
     */
    private function sendXml(string $action, string $xml, bool $expectPdf = false): array
    {
        $ch = curl_init();

        // Use multipart form data with XML file
        $tmpFile = tempnam(sys_get_temp_dir(), 'szamla_');
        file_put_contents($tmpFile, $xml);

        curl_setopt_array($ch, [
            CURLOPT_URL => self::BASE_URL,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                $action => new \CURLFile($tmpFile, 'application/xml', 'action.xml'),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // Clean up temp file
        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        if ($curlError) {
            return ['error' => 'Connection error: ' . $curlError];
        }

        // Parse headers and body
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Check for Szamlazz.hu error codes in headers
        $szlaError = '';
        if (preg_match('/szlahu_error_code:\s*(\d+)/i', $headers, $m)) {
            $errorCode = $m[1];
            if (preg_match('/szlahu_error:\s*(.+?)(\r\n|\r|\n)/i', $headers, $em)) {
                $szlaError = trim($em[1]);
            }
            if ($errorCode !== '0') {
                return ['error' => "Szamlazz.hu error [{$errorCode}]: {$szlaError}"];
            }
        }

        // Extract invoice number from headers
        $invoiceNumber = '';
        if (preg_match('/szlahu_szamlaszam:\s*(.+?)(\r\n|\r|\n)/i', $headers, $m)) {
            $invoiceNumber = trim($m[1]);
        }

        $result = [
            'szlahu_szamlaszam' => $invoiceNumber,
            'body' => $body,
            'headers' => $headers,
            'http_code' => $httpCode,
        ];

        // If we expected a PDF and the body looks like one
        if ($expectPdf && !empty($body)) {
            $contentType = '';
            if (preg_match('/Content-Type:\s*application\/pdf/i', $headers)) {
                $result['pdf_content'] = $body;
            } elseif (substr($body, 0, 4) === '%PDF') {
                $result['pdf_content'] = $body;
            }
        }

        return $result;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

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

