<?php

namespace Webmail\Addons\CrmPro\Controllers;

use Webmail\Controllers\BaseController;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\CrmPro\Services\CrmInvoiceService;

/**
 * CrmInvoiceController
 * 
 * Handles all invoice and expense API endpoints for CRM Pro.
 * All endpoints require JWT authentication.
 */
class CrmInvoiceController extends BaseController
{
    private CrmInvoiceService $invoiceService;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->invoiceService = new CrmInvoiceService($config);
    }

    // =========================================================================
    // Invoices
    // =========================================================================

    /**
     * List invoices with optional filters
     * GET /crm/invoices?client_id=&status=&from_date=&to_date=&search=
     */
    public function list(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $filters = [
            'client_id' => $request->getQuery('client_id'),
            'status' => $request->getQuery('status'),
            'from_date' => $request->getQuery('from_date'),
            'to_date' => $request->getQuery('to_date'),
            'search' => $request->getQuery('search'),
            'overdue' => $request->getQuery('overdue'),
        ];

        // Also mark overdue invoices while listing
        $this->invoiceService->markOverdue($this->userEmail);

        $clientId = $filters['client_id'] ? (int)$filters['client_id'] : null;
        $invoices = $this->invoiceService->listInvoices($this->userEmail, array_filter($filters));
        $summary = $this->invoiceService->getSummary($this->userEmail, $clientId);

        return Response::success([
            'invoices' => $invoices,
            'summary' => $summary,
        ]);
    }

    /**
     * Get a single invoice with items and payments
     * GET /crm/invoices/{id}
     */
    public function get(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $invoice = $this->invoiceService->getInvoice($id, $this->userEmail);

        if (!$invoice) {
            return Response::notFound('Invoice not found');
        }

        return Response::success($invoice);
    }

    /**
     * Create a new invoice
     * POST /crm/invoices
     */
    public function create(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $data = $request->input();
        if (empty($data['client_id'])) {
            return Response::badRequest('client_id is required');
        }

        try {
            $invoice = $this->invoiceService->createInvoice($this->userEmail, $data);
            return Response::success($invoice, 'Invoice created');
        } catch (\Throwable $e) {
            error_log("CrmInvoiceController::create error: " . $e->getMessage());
            return Response::serverError('Failed to create invoice');
        }
    }

    /**
     * Update an invoice
     * PUT /crm/invoices/{id}
     */
    public function update(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $data = $request->input();

        try {
            $invoice = $this->invoiceService->updateInvoice($id, $this->userEmail, $data);
            if (!$invoice) {
                return Response::notFound('Invoice not found');
            }
            return Response::success($invoice, 'Invoice updated');
        } catch (\Throwable $e) {
            error_log("CrmInvoiceController::update error: " . $e->getMessage());
            return Response::serverError('Failed to update invoice');
        }
    }

    /**
     * Delete an invoice (draft only)
     * DELETE /crm/invoices/{id}
     */
    public function delete(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');

        try {
            $deleted = $this->invoiceService->deleteInvoice($id, $this->userEmail);
            if (!$deleted) {
                return Response::notFound('Invoice not found');
            }
            return Response::success(null, 'Invoice deleted');
        } catch (\RuntimeException $e) {
            return Response::badRequest($e->getMessage());
        }
    }

    /**
     * Mark invoice as sent
     * POST /crm/invoices/{id}/send
     */
    public function send(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $invoice = $this->invoiceService->markSent($id, $this->userEmail);

        if (!$invoice) {
            return Response::badRequest('Invoice not found or not in draft status');
        }

        return Response::success($invoice, 'Invoice marked as sent');
    }

    /**
     * Record a payment against an invoice
     * POST /crm/invoices/{id}/payment
     */
    public function recordPayment(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $data = $request->input();

        if (empty($data['amount']) || (float)$data['amount'] <= 0) {
            return Response::badRequest('Positive payment amount is required');
        }

        try {
            $invoice = $this->invoiceService->recordPayment($id, $this->userEmail, $data);
            return Response::success($invoice, 'Payment recorded');
        } catch (\RuntimeException $e) {
            return Response::badRequest($e->getMessage());
        }
    }

    /**
     * Generate invoice PDF (returns HTML for now, can pipe to wkhtmltopdf)
     * GET /crm/invoices/{id}/pdf
     */
    public function generatePdf(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $html = $this->invoiceService->generateInvoiceHtml($id, $this->userEmail);

        if (!$html) {
            return Response::notFound('Invoice not found');
        }

        // Return HTML that can be printed to PDF in the browser
        return Response::success(['html' => $html]);
    }

    // =========================================================================
    // Expenses
    // =========================================================================

    /**
     * List expenses with optional filters
     * GET /crm/expenses?client_id=&category=&from_date=&to_date=
     */
    public function listExpenses(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $filters = [
            'client_id' => $request->getQuery('client_id'),
            'category' => $request->getQuery('category'),
            'from_date' => $request->getQuery('from_date'),
            'to_date' => $request->getQuery('to_date'),
        ];

        $expenses = $this->invoiceService->listExpenses($this->userEmail, array_filter($filters));
        return Response::success(['expenses' => $expenses]);
    }

    /**
     * Create an expense
     * POST /crm/expenses
     */
    public function createExpense(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $data = $request->input();
        if (empty($data['client_id']) || empty($data['description']) || empty($data['amount'])) {
            return Response::badRequest('client_id, description, and amount are required');
        }

        try {
            $expense = $this->invoiceService->createExpense($this->userEmail, $data);
            return Response::success($expense, 'Expense created');
        } catch (\Throwable $e) {
            error_log("CrmInvoiceController::createExpense error: " . $e->getMessage());
            return Response::serverError('Failed to create expense');
        }
    }

    /**
     * Update an expense
     * PUT /crm/expenses/{id}
     */
    public function updateExpense(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $data = $request->input();

        $expense = $this->invoiceService->updateExpense($id, $this->userEmail, $data);
        if (!$expense) {
            return Response::notFound('Expense not found');
        }

        return Response::success($expense, 'Expense updated');
    }

    /**
     * Delete an expense
     * DELETE /crm/expenses/{id}
     */
    public function deleteExpense(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $id = (int)$request->getParam('id');
        $deleted = $this->invoiceService->deleteExpense($id, $this->userEmail);

        if (!$deleted) {
            return Response::notFound('Expense not found');
        }

        return Response::success(null, 'Expense deleted');
    }
}

