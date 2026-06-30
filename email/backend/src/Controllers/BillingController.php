<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\Billing\BillingService;

/**
 * BillingController
 * 
 * Handles billing provider integration endpoints.
 * Settings management, push-to-provider, PDF download, status sync, email send.
 */
class BillingController extends BaseController
{
    private BillingService $billingService;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->billingService = new BillingService($config);
    }

    // =========================================================================
    // Settings
    // =========================================================================

    /**
     * GET /billing/settings
     * Get current billing settings for the logged-in user
     */
    public function getSettings(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $settings = $this->billingService->getSettings($this->userEmail);
        return Response::success($settings ?? ['provider' => 'none']);
    }

    /**
     * PUT /billing/settings
     * Save billing settings (provider, API key, company details, defaults)
     */
    public function saveSettings(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $data = $request->input();
        if (empty($data)) {
            return Response::badRequest('No settings data provided');
        }

        try {
            $settings = $this->billingService->saveSettings($this->userEmail, $data);
            return Response::success($settings, 'Billing settings saved');
        } catch (\Throwable $e) {
            $debugMsg = date('Y-m-d H:i:s') . " BillingController::saveSettings error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
            error_log($debugMsg);
            file_put_contents(__DIR__ . '/../../storage/logs/billing-debug.log', $debugMsg, FILE_APPEND);
            return Response::serverError('Failed to save billing settings: ' . $e->getMessage());
        }
    }

    /**
     * POST /billing/test-connection
     * Test the connection to the configured billing provider
     */
    public function testConnection(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        try {
            $result = $this->billingService->testConnection($this->userEmail);
            if ($result['success']) {
                return Response::success($result['data'] ?? [], $result['message'] ?? 'Connected');
            }
            $msg = $result['message'] ?? 'Connection failed';
            return Response::badRequest(is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            $debugMsg = date('Y-m-d H:i:s') . " BillingController::testConnection error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString() . "\n";
            error_log($debugMsg);
            file_put_contents(__DIR__ . '/../../storage/logs/billing-debug.log', $debugMsg, FILE_APPEND);
            return Response::serverError('Connection test failed: ' . $e->getMessage());
        }
    }

    /**
     * GET /billing/invoice-blocks
     * Get available invoice blocks from the provider (Billingo)
     */
    public function getInvoiceBlocks(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $result = $this->billingService->getInvoiceBlocks($this->userEmail);
        if ($result['success']) {
            return Response::success(['blocks' => $result['blocks'] ?? []]);
        }
        return Response::badRequest($result['error'] ?? 'Failed to get blocks');
    }

    // =========================================================================
    // Invoice Operations
    // =========================================================================

    /**
     * POST /crm/invoices/{id}/push
     * Push a CRM invoice to the external billing provider
     */
    public function pushToProvider(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $invoiceId = (int)$request->getParam('id');
        $electronic = (bool)($request->input('electronic') ?? true);

        try {
            $result = $this->billingService->pushToProvider($invoiceId, $this->userEmail, $electronic);

            if ($result['success']) {
                return Response::success($result, $result['message'] ?? 'Invoice pushed to billing provider');
            }
            $errorMsg = $result['error'] ?? $result['message'] ?? 'Failed to push invoice';
            if (is_array($errorMsg)) $errorMsg = json_encode($errorMsg, JSON_UNESCAPED_UNICODE);
            return Response::badRequest($errorMsg);
        } catch (\Throwable $e) {
            error_log("BillingController::pushToProvider error: " . $e->getMessage());
            return Response::serverError('Failed to push invoice: ' . $e->getMessage());
        }
    }

    /**
     * GET /crm/invoices/{id}/download-pdf
     * Download the invoice PDF from external billing provider
     */
    public function downloadPdf(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $invoiceId = (int)$request->getParam('id');

        $result = $this->billingService->downloadPdf($invoiceId, $this->userEmail);

        if ($result['success']) {
            return Response::success($result);
        }
        return Response::badRequest($result['error'] ?? 'Failed to download PDF');
    }

    /**
     * POST /crm/invoices/{id}/sync-status
     * Sync invoice payment status from external provider
     */
    public function syncStatus(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $invoiceId = (int)$request->getParam('id');

        $result = $this->billingService->syncStatus($invoiceId, $this->userEmail);

        if ($result['success']) {
            return Response::success($result, 'Status synced');
        }
        return Response::badRequest($result['error'] ?? 'Failed to sync status');
    }

    /**
     * POST /crm/invoices/{id}/cancel-external
     * Cancel/storno the invoice on the external provider
     */
    public function cancelExternal(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $invoiceId = (int)$request->getParam('id');

        $result = $this->billingService->cancelOnProvider($invoiceId, $this->userEmail);

        if ($result['success']) {
            return Response::success(null, $result['message'] ?? 'Invoice cancelled on provider');
        }
        return Response::badRequest($result['error'] ?? 'Failed to cancel invoice');
    }

    /**
     * POST /crm/invoices/{id}/send-email
     * Send invoice PDF to client via email (uses user's SMTP credentials)
     */
    public function sendEmail(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        if (!$this->userPassword) {
            return Response::badRequest('Session password not available. Please re-login to send emails.');
        }

        $invoiceId = (int)$request->getParam('id');
        $options = [
            'recipient_email' => $request->input('recipient_email'),
            'subject' => $request->input('subject'),
            'body' => $request->input('body'),
        ];

        $result = $this->billingService->sendToClient(
            $invoiceId,
            $this->userEmail,
            $this->userPassword,
            array_filter($options)
        );

        if ($result['success']) {
            return Response::success(null, $result['message'] ?? 'Invoice sent');
        }
        return Response::badRequest($result['error'] ?? 'Failed to send invoice');
    }
}

