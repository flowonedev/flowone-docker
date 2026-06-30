<?php

namespace Webmail\Services\Billing;

use PDO;
use Webmail\Services\DriveService;
use Webmail\Services\SmtpService;

/**
 * BillingService - Orchestrator for billing provider integration
 * 
 * This is the single entry point for all billing operations.
 * It coordinates between:
 * - Our local CRM invoice records (crm_invoices)
 * - External billing platform (Billingo or Szamlazz.hu)
 * - Drive storage (auto-save PDFs)
 * - Email sending (send invoice to client)
 * 
 * ONE source of truth: the external billing platform is the legal invoice.
 * Our crm_invoices table is just a mirror / reference.
 */
class BillingService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    // =========================================================================
    // Settings CRUD
    // =========================================================================

    /**
     * Get billing settings for a user (decrypts API key)
     */
    public function getSettings(string $userEmail): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM crm_billing_settings WHERE user_email = ?');
        $stmt->execute([strtolower($userEmail)]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        // Decrypt API key for display (masked)
        $row['api_key_masked'] = $this->maskApiKey($row['api_key'] ?? '');
        $row['has_api_key'] = !empty($row['api_key']);
        unset($row['api_key']); // Never send raw encrypted key to frontend

        // Decrypt szamlazz agent key for display (masked)
        $row['szamlazz_agent_key_masked'] = $this->maskApiKey($row['szamlazz_agent_key'] ?? '');
        $row['has_szamlazz_agent_key'] = !empty($row['szamlazz_agent_key']);
        unset($row['szamlazz_agent_key']); // Never send raw encrypted key to frontend

        return $row;
    }

    /**
     * Save billing settings
     */
    public function saveSettings(string $userEmail, array $data): array
    {
        $userEmail = strtolower($userEmail);
        $existing = $this->getRawSettings($userEmail);

        // Encrypt API key if provided (non-empty string)
        $apiKey = $data['api_key'] ?? null;
        if ($apiKey && trim($apiKey) !== '' && $apiKey !== '********') {
            $apiKey = $this->encryptValue($apiKey);
        } else {
            $apiKey = $existing['api_key'] ?? null;
        }

        // Encrypt Szamlazz agent key if provided
        $szamlazzKey = $data['szamlazz_agent_key'] ?? null;
        if ($szamlazzKey && trim($szamlazzKey) !== '' && $szamlazzKey !== '********') {
            $szamlazzKey = $this->encryptValue($szamlazzKey);
        } else {
            $szamlazzKey = $existing['szamlazz_agent_key'] ?? null;
        }

        // Helper: convert empty strings to null for nullable fields
        $nullIfEmpty = fn($val) => ($val === '' || $val === null) ? null : $val;
        $intOrNull = fn($val) => ($val === '' || $val === null) ? null : (int)$val;

        $params = [
            'user_email' => $userEmail,
            'provider' => $data['provider'] ?? $existing['provider'] ?? 'none',
            'api_key' => $apiKey,
            'billingo_block_id' => $intOrNull($data['billingo_block_id'] ?? $existing['billingo_block_id'] ?? null),
            'szamlazz_agent_key' => $szamlazzKey,
            'company_name' => $nullIfEmpty($data['company_name'] ?? $existing['company_name'] ?? null),
            'company_address' => $nullIfEmpty($data['company_address'] ?? $existing['company_address'] ?? null),
            'company_tax_number' => $nullIfEmpty($data['company_tax_number'] ?? $existing['company_tax_number'] ?? null),
            'company_eu_tax_number' => $nullIfEmpty($data['company_eu_tax_number'] ?? $existing['company_eu_tax_number'] ?? null),
            'company_bank_account' => $nullIfEmpty($data['company_bank_account'] ?? $existing['company_bank_account'] ?? null),
            'company_bank_name' => $nullIfEmpty($data['company_bank_name'] ?? $existing['company_bank_name'] ?? null),
            'company_email' => $nullIfEmpty($data['company_email'] ?? $existing['company_email'] ?? null),
            'company_phone' => $nullIfEmpty($data['company_phone'] ?? $existing['company_phone'] ?? null),
            'default_currency' => $data['default_currency'] ?? $existing['default_currency'] ?? 'HUF',
            'default_tax_rate' => $data['default_tax_rate'] ?? $existing['default_tax_rate'] ?? 27.00,
            'default_payment_terms_days' => $intOrNull($data['default_payment_terms_days'] ?? $existing['default_payment_terms_days'] ?? 8) ?? 8,
            'default_payment_method' => $data['default_payment_method'] ?? $existing['default_payment_method'] ?? 'bank_transfer',
            'default_language' => $data['default_language'] ?? $existing['default_language'] ?? 'hu',
            'auto_save_to_drive' => isset($data['auto_save_to_drive']) ? (int)$data['auto_save_to_drive'] : ($existing['auto_save_to_drive'] ?? 1),
        ];

        if ($existing) {
            // Update
            $sets = [];
            $values = [];
            foreach ($params as $key => $val) {
                if ($key === 'user_email') continue;
                $sets[] = "{$key} = ?";
                $values[] = $val;
            }
            $values[] = $userEmail;
            $this->db->prepare('UPDATE crm_billing_settings SET ' . implode(', ', $sets) . ' WHERE user_email = ?')
                ->execute($values);
        } else {
            // Insert
            $cols = array_keys($params);
            $placeholders = array_fill(0, count($cols), '?');
            $this->db->prepare(
                'INSERT INTO crm_billing_settings (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $placeholders) . ')'
            )->execute(array_values($params));
        }

        return $this->getSettings($userEmail);
    }

    /**
     * Test connection to the configured billing provider
     */
    public function testConnection(string $userEmail): array
    {
        $settings = $this->getRawSettings($userEmail);
        if (!$settings) {
            return ['success' => false, 'message' => 'No billing settings configured'];
        }

        $provider = $this->getProvider($settings);
        if (!$provider) {
            return ['success' => false, 'message' => 'No billing provider configured'];
        }

        return $provider->testConnection();
    }

    /**
     * Get invoice blocks from the provider (for Billingo block selection)
     */
    public function getInvoiceBlocks(string $userEmail): array
    {
        $settings = $this->getRawSettings($userEmail);
        if (!$settings) {
            return ['success' => false, 'error' => 'No billing settings configured'];
        }

        $provider = $this->getProvider($settings);
        if (!$provider) {
            return ['success' => false, 'error' => 'No billing provider configured'];
        }

        return $provider->getInvoiceBlocks();
    }

    // =========================================================================
    // Invoice Operations (push to external provider)
    // =========================================================================

    /**
     * Push a CRM invoice to the external billing platform
     * Creates the legal invoice externally and stores the reference
     * 
     * @param int $invoiceId Our local CRM invoice ID
     * @param string $userEmail
     * @param bool $electronic Send electronically via billing platform
     * @return array
     */
    public function pushToProvider(int $invoiceId, string $userEmail, bool $electronic = true): array
    {
        $settings = $this->getRawSettings($userEmail);
        if (!$settings) {
            return ['success' => false, 'error' => 'No billing settings configured. Go to Settings to configure.'];
        }

        $provider = $this->getProvider($settings);
        if (!$provider) {
            return ['success' => false, 'error' => 'No billing provider configured. Go to Settings to select a provider.'];
        }

        // Fetch local invoice with items and client info
        $invoice = $this->getInvoiceWithClient($invoiceId, $userEmail);
        if (!$invoice) {
            return ['success' => false, 'error' => 'Invoice not found'];
        }

        // Check it hasn't already been pushed
        if (!empty($invoice['external_invoice_id'])) {
            return ['success' => false, 'error' => 'Invoice already pushed to ' . ($invoice['billing_provider'] ?? 'provider') . ' (ID: ' . $invoice['external_invoice_id'] . ')'];
        }

        // Build the payload for the provider
        $providerData = [
            'client_name' => $invoice['client_name'] ?? '',
            'client_billing_name' => $invoice['client_billing_name'] ?? '',
            'client_tax_number' => $invoice['client_tax_number'] ?? '',
            'client_address' => $invoice['client_address'] ?? $this->formatClientAddress($invoice),
            'client_city' => $invoice['client_city'] ?? '',
            'client_zip' => $invoice['client_zip'] ?? '',
            'client_country' => $invoice['client_country'] ?? 'HU',
            'client_email' => $invoice['client_email'] ?? '',
            'issue_date' => $invoice['issue_date'] ?? date('Y-m-d'),
            'due_date' => $invoice['due_date'] ?? date('Y-m-d', strtotime('+' . ($settings['default_payment_terms_days'] ?? 8) . ' days')),
            'currency' => $invoice['currency'] ?? $settings['default_currency'] ?? 'HUF',
            'language' => $settings['default_language'] ?? 'hu',
            'payment_method' => $settings['default_payment_method'] ?? 'bank_transfer',
            'tax_rate' => (float)($invoice['tax_rate'] ?? $settings['default_tax_rate'] ?? 27),
            'items' => array_map(fn($item) => [
                'description' => $item['description'] ?? '',
                'quantity' => (float)($item['quantity'] ?? 1),
                'unit' => $item['unit'] ?? 'db',
                'unit_price' => (float)($item['unit_price'] ?? 0),
                'tax_rate' => (float)($item['tax_rate'] ?? $invoice['tax_rate'] ?? $settings['default_tax_rate'] ?? 27),
            ], $invoice['items'] ?? []),
            'notes' => $invoice['notes'] ?? '',
            'electronic' => $electronic,
        ];

        // Push to external provider
        $result = $provider->createInvoice($providerData);

        if (!$result['success']) {
            return $result;
        }

        // Update our local invoice record with external references
        $this->db->prepare('
            UPDATE crm_invoices SET
                billing_provider = ?,
                external_invoice_id = ?,
                external_invoice_url = ?,
                external_pdf_url = ?,
                invoice_number = COALESCE(?, invoice_number),
                status = CASE WHEN status = \'draft\' THEN \'sent\' ELSE status END,
                sent_at = COALESCE(sent_at, NOW())
            WHERE id = ? AND user_email = ?
        ')->execute([
            $provider->getProviderName(),
            $result['external_id'] ?? null,
            $result['external_url'] ?? null,
            $result['external_pdf_url'] ?? null,
            $result['invoice_number'] ?? null,
            $invoiceId,
            $userEmail,
        ]);

        // Auto-save PDF to Drive if enabled
        $logFile = dirname(__DIR__, 3) . '/logs/php_errors.log';
        error_log("[BillingPush] Push success, auto_save_to_drive=" . var_export($settings['auto_save_to_drive'] ?? 'NOT SET', true) . "\n", 3, $logFile);
        $driveFile = null;
        if ($settings['auto_save_to_drive'] ?? true) {
            error_log("[BillingPush] Calling savePdfToDrive...\n", 3, $logFile);
            $driveFile = $this->savePdfToDrive($invoiceId, $userEmail, $result, $settings);
            error_log("[BillingPush] savePdfToDrive returned: " . ($driveFile ? 'file saved' : 'null') . "\n", 3, $logFile);
        } else {
            error_log("[BillingPush] auto_save_to_drive is disabled, skipping\n", 3, $logFile);
        }

        return [
            'success' => true,
            'external_id' => $result['external_id'] ?? null,
            'external_url' => $result['external_url'] ?? null,
            'invoice_number' => $result['invoice_number'] ?? null,
            'drive_file' => $driveFile,
            'message' => $result['message'] ?? 'Invoice pushed to billing provider',
        ];
    }

    /**
     * Download PDF from external provider and optionally save to Drive
     */
    public function downloadPdf(int $invoiceId, string $userEmail): array
    {
        $invoice = $this->getInvoiceBasic($invoiceId, $userEmail);
        if (!$invoice) {
            return ['success' => false, 'error' => 'Invoice not found'];
        }

        if (empty($invoice['external_invoice_id'])) {
            return ['success' => false, 'error' => 'Invoice has no external provider reference'];
        }

        $settings = $this->getRawSettings($userEmail);
        $provider = $this->getProvider($settings);
        if (!$provider) {
            return ['success' => false, 'error' => 'Billing provider not configured'];
        }

        $result = $provider->downloadPdf($invoice['external_invoice_id']);

        if (!$result['success']) {
            return $result;
        }

        // Return base64 encoded PDF for frontend
        return [
            'success' => true,
            'pdf_base64' => base64_encode($result['pdf_content']),
            'filename' => $result['filename'] ?? ('invoice_' . $invoice['invoice_number'] . '.pdf'),
            'mime_type' => 'application/pdf',
        ];
    }

    /**
     * Sync status from external provider
     */
    public function syncStatus(int $invoiceId, string $userEmail): array
    {
        $invoice = $this->getInvoiceBasic($invoiceId, $userEmail);
        if (!$invoice || empty($invoice['external_invoice_id'])) {
            return ['success' => false, 'error' => 'Invoice not found or has no external reference'];
        }

        $settings = $this->getRawSettings($userEmail);
        $provider = $this->getProvider($settings);
        if (!$provider) {
            return ['success' => false, 'error' => 'Billing provider not configured'];
        }

        $result = $provider->getStatus($invoice['external_invoice_id']);

        if ($result['success'] && !empty($result['status'])) {
            $this->db->prepare('UPDATE crm_invoices SET status = ? WHERE id = ? AND user_email = ?')
                ->execute([$result['status'], $invoiceId, $userEmail]);
        }

        return $result;
    }

    /**
     * Cancel invoice on external provider (storno)
     */
    public function cancelOnProvider(int $invoiceId, string $userEmail): array
    {
        $invoice = $this->getInvoiceBasic($invoiceId, $userEmail);
        if (!$invoice || empty($invoice['external_invoice_id'])) {
            return ['success' => false, 'error' => 'Invoice not found or has no external reference'];
        }

        $settings = $this->getRawSettings($userEmail);
        $provider = $this->getProvider($settings);
        if (!$provider) {
            return ['success' => false, 'error' => 'Billing provider not configured'];
        }

        $result = $provider->cancelInvoice($invoice['external_invoice_id']);

        if ($result['success']) {
            $this->db->prepare('UPDATE crm_invoices SET status = ? WHERE id = ? AND user_email = ?')
                ->execute(['cancelled', $invoiceId, $userEmail]);
        }

        return $result;
    }

    /**
     * Send invoice PDF to client via email (using the user's SMTP credentials)
     */
    public function sendToClient(int $invoiceId, string $userEmail, string $senderPassword, array $options = []): array
    {
        $invoice = $this->getInvoiceWithClient($invoiceId, $userEmail);
        if (!$invoice) {
            return ['success' => false, 'error' => 'Invoice not found'];
        }

        $recipientEmail = $options['recipient_email'] ?? $invoice['client_email'] ?? null;
        if (!$recipientEmail) {
            return ['success' => false, 'error' => 'No recipient email. Set client email or provide one.'];
        }

        // Get the PDF - either from the provider or generate locally
        $pdfContent = null;
        $filename = 'invoice_' . ($invoice['invoice_number'] ?? $invoiceId) . '.pdf';

        if (!empty($invoice['external_invoice_id'])) {
            $settings = $this->getRawSettings($userEmail);
            $provider = $this->getProvider($settings);
            if ($provider) {
                $pdfResult = $provider->downloadPdf($invoice['external_invoice_id']);
                if ($pdfResult['success']) {
                    $pdfContent = $pdfResult['pdf_content'];
                    $filename = $pdfResult['filename'] ?? $filename;
                }
            }
        }

        // Build email
        $clientName = $invoice['client_name'] ?? 'Client';
        $subject = $options['subject'] ?? 'Invoice ' . ($invoice['invoice_number'] ?? '#' . $invoiceId);
        $body = $options['body'] ?? $this->buildInvoiceEmailHtml($invoice);

        try {
            $smtp = new SmtpService($this->config['smtp']);
            $smtp->setCredentials($userEmail, $senderPassword);

            $emailParams = [
                'to' => [['email' => $recipientEmail, 'name' => $clientName]],
                'subject' => $subject,
                'html' => $body,
            ];

            // Attach PDF if available
            if ($pdfContent) {
                $emailParams['attachments'] = [
                    [
                        'content' => $pdfContent,
                        'filename' => $filename,
                        'type' => 'application/pdf',
                    ],
                ];
            }

            $result = $smtp->send($emailParams);

            // Mark as sent if still in draft
            $this->db->prepare('
                UPDATE crm_invoices 
                SET status = CASE WHEN status = \'draft\' THEN \'sent\' ELSE status END,
                    sent_at = COALESCE(sent_at, NOW())
                WHERE id = ? AND user_email = ?
            ')->execute([$invoiceId, $userEmail]);

            return ['success' => true, 'message' => 'Invoice sent to ' . $recipientEmail];
        } catch (\Throwable $e) {
            error_log("BillingService::sendToClient error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to send email: ' . $e->getMessage()];
        }
    }

    // =========================================================================
    // Drive Storage
    // =========================================================================

    /**
     * Save invoice PDF to the user's Drive in an Invoices system folder
     */
    private function savePdfToDrive(int $invoiceId, string $userEmail, array $providerResult, array $settings): ?array
    {
        try {
            $logFile = dirname(__DIR__, 3) . '/logs/php_errors.log';
            $log = function(string $msg) use ($logFile) { error_log($msg . "\n", 3, $logFile); };
            $log("[BillingDrive] Starting savePdfToDrive for invoice {$invoiceId}, user {$userEmail}");
            $driveService = new DriveService($this->config, $userEmail);

            // Get or create the Invoices system folder
            $folderId = $settings['drive_invoices_folder_id'] ?? null;
            $log("[BillingDrive] Existing folder ID from settings: " . var_export($folderId, true));
            if (!$folderId) {
                $folder = $driveService->findOrCreateFolder($userEmail, 'Invoices', null);
                if ($folder) {
                    $folderId = (int)$folder['id'];
                    // Save the folder ID for future use
                    $this->db->prepare('UPDATE crm_billing_settings SET drive_invoices_folder_id = ? WHERE user_email = ?')
                        ->execute([$folderId, $userEmail]);
                    $log("[BillingDrive] Created/found Invoices folder ID: {$folderId}");
                }
            }

            if (!$folderId) {
                $log("[BillingDrive] Could not create Invoices folder for {$userEmail}");
                return null;
            }

            // Get the PDF content
            $pdfContent = $providerResult['pdf_content'] ?? null;
            $log("[BillingDrive] PDF content from provider result: " . ($pdfContent ? strlen($pdfContent) . ' bytes' : 'null'));
            $log("[BillingDrive] Provider result keys: " . implode(', ', array_keys($providerResult)));

            if (!$pdfContent) {
                // If the provider didn't return PDF inline (Billingo), download it
                $rawSettings = $this->getRawSettings($userEmail);
                $provider = $this->getProvider($rawSettings);
                $log("[BillingDrive] Provider: " . ($provider ? get_class($provider) : 'null') . ", external_id: " . ($providerResult['external_id'] ?? 'empty'));
                if ($provider && !empty($providerResult['external_id'])) {
                    $pdfResult = $provider->downloadPdf($providerResult['external_id']);
                    $log("[BillingDrive] Download result: success=" . var_export($pdfResult['success'] ?? false, true) . ", size=" . strlen($pdfResult['pdf_content'] ?? '') . ", error=" . ($pdfResult['error'] ?? 'none'));
                    if ($pdfResult['success']) {
                        $pdfContent = $pdfResult['pdf_content'];
                    }
                }
            }

            if (!$pdfContent) {
                $log("[BillingDrive] No PDF content available, aborting");
                return null;
            }

            // Upload to Drive
            $filename = ($providerResult['invoice_number'] ?? 'invoice_' . $invoiceId) . '.pdf';
            $log("[BillingDrive] Uploading {$filename} (" . strlen($pdfContent) . " bytes) to folder {$folderId}");
            $driveFile = $driveService->uploadFileContent(
                $userEmail,
                $filename,
                $pdfContent,
                'application/pdf',
                $folderId
            );
            $log("[BillingDrive] Upload result: " . ($driveFile ? json_encode($driveFile) : 'null'));

            // Search indexing is handled inside DriveService::uploadFileContent().

            return $driveFile;
        } catch (\Throwable $e) {
            $log("[BillingDrive] ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            return null;
        }
    }

    // =========================================================================
    // Private Helpers
    // =========================================================================

    /**
     * Get raw settings (including encrypted keys) - internal use only
     */
    private function getRawSettings(string $userEmail): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM crm_billing_settings WHERE user_email = ?');
        $stmt->execute([strtolower($userEmail)]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Instantiate the correct billing provider from settings
     */
    private function getProvider(?array $settings): ?BillingProviderInterface
    {
        if (!$settings) return null;

        $providerName = $settings['provider'] ?? 'none';

        switch ($providerName) {
            case 'billingo':
                $apiKey = $this->decryptValue($settings['api_key'] ?? '');
                if (!$apiKey) return null;
                return new BillingoProvider(
                    $apiKey,
                    $settings['billingo_block_id'] ? (int)$settings['billingo_block_id'] : null
                );

            case 'szamlazz':
                $agentKey = $this->decryptValue($settings['szamlazz_agent_key'] ?? '');
                if (!$agentKey) return null;
                return new SzamlazzProvider($agentKey);

            default:
                return null;
        }
    }

    /**
     * Get invoice with client info and items
     */
    private function getInvoiceWithClient(int $invoiceId, string $userEmail): ?array
    {
        $stmt = $this->db->prepare('
            SELECT i.*, 
                   c.display_name as client_name, c.domain as client_domain,
                   c.billing_name as client_billing_name, c.billing_address as client_address,
                   c.billing_city as client_city, c.billing_zip as client_zip,
                   c.billing_country as client_country, c.billing_tax_id as client_tax_number
            FROM crm_invoices i
            LEFT JOIN clients c ON i.client_id = c.id
            WHERE i.id = ? AND i.user_email = ?
        ');
        $stmt->execute([$invoiceId, strtolower($userEmail)]);
        $invoice = $stmt->fetch();

        if (!$invoice) return null;

        // Get primary contact email (fall back to most active contact if no primary set)
        $contactStmt = $this->db->prepare('
            SELECT email FROM client_contacts WHERE client_id = ? ORDER BY email_count DESC LIMIT 1
        ');
        $contactStmt->execute([$invoice['client_id']]);
        $contact = $contactStmt->fetch();
        $invoice['client_email'] = $contact['email'] ?? null;

        // Get items
        $itemStmt = $this->db->prepare('SELECT * FROM crm_invoice_items WHERE invoice_id = ? ORDER BY sort_order');
        $itemStmt->execute([$invoiceId]);
        $invoice['items'] = $itemStmt->fetchAll();

        return $invoice;
    }

    /**
     * Get basic invoice info (no joins)
     */
    private function getInvoiceBasic(int $invoiceId, string $userEmail): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM crm_invoices WHERE id = ? AND user_email = ?');
        $stmt->execute([$invoiceId, strtolower($userEmail)]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Format client address from separate fields
     */
    private function formatClientAddress(array $invoice): string
    {
        $parts = array_filter([
            $invoice['client_address'] ?? '',
            $invoice['client_zip'] ?? '',
            $invoice['client_city'] ?? '',
            $invoice['client_country'] ?? '',
        ]);
        return implode(', ', $parts);
    }

    /**
     * Build HTML email body for sending invoice
     */
    private function buildInvoiceEmailHtml(array $invoice): string
    {
        $clientName = $invoice['client_name'] ?? 'Client';
        $invoiceNumber = $invoice['invoice_number'] ?? '#' . $invoice['id'];
        $total = number_format((float)($invoice['total'] ?? 0), 2, '.', ',');
        $currency = $invoice['currency'] ?? 'HUF';
        $dueDate = $invoice['due_date'] ?? '';

        return "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #333;'>Invoice {$invoiceNumber}</h2>
            <p>Dear {$clientName},</p>
            <p>Please find attached your invoice.</p>
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr>
                    <td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Invoice Number:</strong></td>
                    <td style='padding: 8px; border-bottom: 1px solid #eee;'>{$invoiceNumber}</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Amount:</strong></td>
                    <td style='padding: 8px; border-bottom: 1px solid #eee;'>{$total} {$currency}</td>
                </tr>
                <tr>
                    <td style='padding: 8px; border-bottom: 1px solid #eee;'><strong>Due Date:</strong></td>
                    <td style='padding: 8px; border-bottom: 1px solid #eee;'>{$dueDate}</td>
                </tr>
            </table>
            <p>If you have any questions about this invoice, please don't hesitate to reach out.</p>
            <p>Thank you for your business!</p>
        </div>
        ";
    }

    /**
     * Encrypt a value for storage
     */
    private function encryptValue(string $value): string
    {
        $secret = $this->config['jwt']['secret'] ?? 'fallback-key';
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($value, 'AES-256-CBC', $secret, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a stored value
     */
    private function decryptValue(string $encrypted): ?string
    {
        if (empty($encrypted)) return null;

        try {
            $secret = $this->config['jwt']['secret'] ?? 'fallback-key';
            $data = base64_decode($encrypted, true);
            if ($data === false || strlen($data) < 17) return null;

            $iv = substr($data, 0, 16);
            $ciphertext = substr($data, 16);
            $result = openssl_decrypt($ciphertext, 'AES-256-CBC', $secret, 0, $iv);
            return $result !== false ? $result : null;
        } catch (\Throwable $e) {
            error_log("BillingService::decryptValue error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Mask an API key for display: show first 4 and last 4 chars
     */
    private function maskApiKey(?string $encrypted): string
    {
        if (!$encrypted) return '';

        $decrypted = $this->decryptValue($encrypted);
        if (!$decrypted || strlen($decrypted) < 8) return '********';

        return substr($decrypted, 0, 4) . str_repeat('*', strlen($decrypted) - 8) . substr($decrypted, -4);
    }
}

