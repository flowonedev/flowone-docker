<?php

namespace Webmail\Addons\CrmPro\Services;

use PDO;

/**
 * CrmInvoiceService
 * 
 * Handles invoice CRUD, auto-numbering, line items, payments,
 * recurring invoice generation, PDF generation, and expense tracking.
 */
class CrmInvoiceService
{
    private PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    public function getDb(): PDO
    {
        return $this->db;
    }

    // =========================================================================
    // Invoice Numbering
    // =========================================================================

    /**
     * Generate next invoice number for a user: INV-YYYY-NNN
     */
    public function generateInvoiceNumber(string $userEmail): string
    {
        $year = date('Y');
        $prefix = "INV-{$year}-";

        $stmt = $this->db->prepare('
            SELECT invoice_number FROM crm_invoices 
            WHERE user_email = ? AND invoice_number LIKE ?
            ORDER BY invoice_number DESC 
            LIMIT 1
        ');
        $stmt->execute([$userEmail, $prefix . '%']);
        $last = $stmt->fetchColumn();

        if ($last) {
            $num = (int)substr($last, strlen($prefix)) + 1;
        } else {
            $num = 1;
        }

        return $prefix . str_pad($num, 3, '0', STR_PAD_LEFT);
    }

    // =========================================================================
    // Invoice CRUD
    // =========================================================================

    /**
     * Create a new invoice with line items
     */
    public function createInvoice(string $userEmail, array $data): array
    {
        $this->db->beginTransaction();
        try {
            $invoiceNumber = $data['invoice_number'] ?? $this->generateInvoiceNumber($userEmail);
            $items = $data['items'] ?? [];

            // Calculate totals from items
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += ($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0);
            }

            $taxRate = (float)($data['tax_rate'] ?? 0);
            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $discountAmount = (float)($data['discount_amount'] ?? 0);
            $total = $subtotal + $taxAmount - $discountAmount;

            $stmt = $this->db->prepare('
                INSERT INTO crm_invoices (
                    client_id, user_email, invoice_number, status, issue_date, due_date,
                    subtotal, tax_rate, tax_amount, discount_amount, total, currency,
                    notes, internal_notes, is_recurring, recurrence_interval, recurrence_end_date,
                    parent_invoice_id, board_card_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                (int)$data['client_id'],
                $userEmail,
                $invoiceNumber,
                $data['status'] ?? 'draft',
                $data['issue_date'] ?? date('Y-m-d'),
                $data['due_date'] ?? date('Y-m-d', strtotime('+30 days')),
                $subtotal,
                $taxRate,
                $taxAmount,
                $discountAmount,
                $total,
                $data['currency'] ?? 'HUF',
                $data['notes'] ?? null,
                $data['internal_notes'] ?? null,
                (int)($data['is_recurring'] ?? 0),
                $data['recurrence_interval'] ?? null,
                $data['recurrence_end_date'] ?? null,
                $data['parent_invoice_id'] ?? null,
                $data['board_card_id'] ?? null,
            ]);
            $invoiceId = (int)$this->db->lastInsertId();

            // Insert line items
            foreach ($items as $i => $item) {
                $itemTotal = ($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0);
                $stmt = $this->db->prepare('
                    INSERT INTO crm_invoice_items (invoice_id, description, quantity, unit, unit_price, tax_rate, total, sort_order, board_card_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([
                    $invoiceId,
                    $item['description'] ?? '',
                    $item['quantity'] ?? 1,
                    $item['unit'] ?? null,
                    $item['unit_price'] ?? 0,
                    $item['tax_rate'] ?? null,
                    $itemTotal,
                    $item['sort_order'] ?? $i,
                    $item['board_card_id'] ?? null,
                ]);
            }

            $this->db->commit();
            return $this->getInvoice($invoiceId, $userEmail);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Get a single invoice with items and payments
     */
    public function getInvoice(int $id, string $userEmail): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM crm_invoices WHERE id = ? AND user_email = ?');
        $stmt->execute([$id, $userEmail]);
        $invoice = $stmt->fetch();

        if (!$invoice) return null;

        // Get items
        $stmt = $this->db->prepare('SELECT * FROM crm_invoice_items WHERE invoice_id = ? ORDER BY sort_order');
        $stmt->execute([$id]);
        $invoice['items'] = $stmt->fetchAll();

        // Get payments
        $stmt = $this->db->prepare('SELECT * FROM crm_invoice_payments WHERE invoice_id = ? ORDER BY payment_date DESC');
        $stmt->execute([$id]);
        $invoice['payments'] = $stmt->fetchAll();

        return $invoice;
    }

    /**
     * List invoices with filters
     */
    public function listInvoices(string $userEmail, array $filters = []): array
    {
        $where = ['i.user_email = ?'];
        $params = [$userEmail];

        if (!empty($filters['client_id'])) {
            $where[] = 'i.client_id = ?';
            $params[] = (int)$filters['client_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'i.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['from_date'])) {
            $where[] = 'i.issue_date >= ?';
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $where[] = 'i.issue_date <= ?';
            $params[] = $filters['to_date'];
        }
        if (!empty($filters['overdue'])) {
            $where[] = 'i.status NOT IN (?, ?, ?) AND i.due_date < CURDATE()';
            $params[] = 'paid';
            $params[] = 'cancelled';
            $params[] = 'refunded';
        }
        if (!empty($filters['search'])) {
            $where[] = '(i.invoice_number LIKE ? OR i.notes LIKE ?)';
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT i.*,
                   (SELECT COUNT(*) FROM crm_invoice_items ii WHERE ii.invoice_id = i.id) as item_count,
                   (SELECT COALESCE(SUM(p.amount), 0) FROM crm_invoice_payments p WHERE p.invoice_id = i.id) as total_paid
            FROM crm_invoices i
            WHERE {$whereClause}
            ORDER BY i.created_at DESC
        ");
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Update an invoice (only if draft or sent)
     */
    public function updateInvoice(int $id, string $userEmail, array $data): ?array
    {
        $invoice = $this->getInvoice($id, $userEmail);
        if (!$invoice) return null;

        // Recalculate if items provided
        $items = $data['items'] ?? null;
        if ($items !== null) {
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += ($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0);
            }
            $taxRate = (float)($data['tax_rate'] ?? $invoice['tax_rate']);
            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $discountAmount = (float)($data['discount_amount'] ?? $invoice['discount_amount']);
            $total = $subtotal + $taxAmount - $discountAmount;

            $data['subtotal'] = $subtotal;
            $data['tax_amount'] = $taxAmount;
            $data['total'] = $total;
        }

        $this->db->beginTransaction();
        try {
            $fields = [];
            $params = [];
            $allowed = [
                'client_id', 'status', 'issue_date', 'due_date', 'subtotal', 'tax_rate',
                'tax_amount', 'discount_amount', 'total', 'currency', 'notes', 'internal_notes',
                'is_recurring', 'recurrence_interval', 'recurrence_end_date', 'board_card_id',
                'payment_method', 'payment_reference',
            ];
            foreach ($allowed as $field) {
                if (array_key_exists($field, $data)) {
                    $fields[] = "{$field} = ?";
                    $params[] = $data[$field];
                }
            }

            if (!empty($fields)) {
                $params[] = $id;
                $params[] = $userEmail;
                $this->db->prepare("UPDATE crm_invoices SET " . implode(', ', $fields) . " WHERE id = ? AND user_email = ?")
                    ->execute($params);
            }

            // Replace items if provided
            if ($items !== null) {
                $this->db->prepare('DELETE FROM crm_invoice_items WHERE invoice_id = ?')->execute([$id]);
                foreach ($items as $i => $item) {
                    $itemTotal = ($item['quantity'] ?? 1) * ($item['unit_price'] ?? 0);
                    $this->db->prepare('
                        INSERT INTO crm_invoice_items (invoice_id, description, quantity, unit, unit_price, tax_rate, total, sort_order, board_card_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ')->execute([
                        $id,
                        $item['description'] ?? '',
                        $item['quantity'] ?? 1,
                        $item['unit'] ?? null,
                        $item['unit_price'] ?? 0,
                        $item['tax_rate'] ?? null,
                        $itemTotal,
                        $item['sort_order'] ?? $i,
                        $item['board_card_id'] ?? null,
                    ]);
                }
            }

            $this->db->commit();
            return $this->getInvoice($id, $userEmail);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete an invoice (only if draft)
     */
    public function deleteInvoice(int $id, string $userEmail): bool
    {
        $stmt = $this->db->prepare('SELECT status FROM crm_invoices WHERE id = ? AND user_email = ?');
        $stmt->execute([$id, $userEmail]);
        $invoice = $stmt->fetch();

        if (!$invoice) return false;
        if ($invoice['status'] !== 'draft') {
            throw new \RuntimeException('Only draft invoices can be deleted');
        }

        $this->db->prepare('DELETE FROM crm_invoices WHERE id = ? AND user_email = ?')->execute([$id, $userEmail]);
        return true;
    }

    // =========================================================================
    // Sending & Status
    // =========================================================================

    /**
     * Mark invoice as sent and record the timestamp
     */
    public function markSent(int $id, string $userEmail): ?array
    {
        $stmt = $this->db->prepare('
            UPDATE crm_invoices SET status = ?, sent_at = NOW() 
            WHERE id = ? AND user_email = ? AND status = ?
        ');
        $stmt->execute(['sent', $id, $userEmail, 'draft']);

        if ($stmt->rowCount() === 0) return null;

        return $this->getInvoice($id, $userEmail);
    }

    /**
     * Check and mark overdue invoices
     */
    public function markOverdue(string $userEmail): int
    {
        $stmt = $this->db->prepare('
            UPDATE crm_invoices 
            SET status = ?
            WHERE user_email = ? AND status IN (?, ?) AND due_date < CURDATE()
        ');
        $stmt->execute(['overdue', $userEmail, 'sent', 'viewed']);

        return $stmt->rowCount();
    }

    // =========================================================================
    // Payments
    // =========================================================================

    /**
     * Record a payment against an invoice (supports partial)
     */
    public function recordPayment(int $invoiceId, string $userEmail, array $data): array
    {
        $invoice = $this->getInvoice($invoiceId, $userEmail);
        if (!$invoice) throw new \RuntimeException('Invoice not found');

        $amount = (float)($data['amount'] ?? 0);
        if ($amount <= 0) throw new \RuntimeException('Payment amount must be positive');

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('
                INSERT INTO crm_invoice_payments (invoice_id, amount, payment_date, payment_method, reference, notes, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $invoiceId,
                $amount,
                $data['payment_date'] ?? date('Y-m-d'),
                $data['payment_method'] ?? null,
                $data['reference'] ?? null,
                $data['notes'] ?? null,
                $userEmail,
            ]);

            // Update invoice paid amount and status
            $newPaid = (float)$invoice['paid_amount'] + $amount;
            $total = (float)$invoice['total'];

            if ($newPaid >= $total) {
                $status = 'paid';
                $paidAt = date('Y-m-d H:i:s');
            } else {
                $status = 'partial';
                $paidAt = null;
            }

            $this->db->prepare('
                UPDATE crm_invoices SET paid_amount = ?, status = ?, paid_at = ?, payment_method = ?
                WHERE id = ?
            ')->execute([
                $newPaid,
                $status,
                $paidAt,
                $data['payment_method'] ?? $invoice['payment_method'],
                $invoiceId,
            ]);

            $this->db->commit();
            return $this->getInvoice($invoiceId, $userEmail);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // =========================================================================
    // Recurring Invoices
    // =========================================================================

    /**
     * Generate next recurring invoices that are due
     */
    public function processRecurring(string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM crm_invoices 
            WHERE user_email = ? AND is_recurring = 1 AND status = ?
            AND (recurrence_end_date IS NULL OR recurrence_end_date >= CURDATE())
        ');
        $stmt->execute([$userEmail, 'paid']);
        $recurring = $stmt->fetchAll();

        $created = [];
        foreach ($recurring as $parent) {
            $nextDate = $this->calculateNextDate($parent['issue_date'], $parent['recurrence_interval']);
            if (!$nextDate || $nextDate > date('Y-m-d')) continue;

            // Check if already generated
            $check = $this->db->prepare('
                SELECT id FROM crm_invoices 
                WHERE parent_invoice_id = ? AND issue_date = ?
            ');
            $check->execute([$parent['id'], $nextDate]);
            if ($check->fetch()) continue;

            // Get parent items
            $itemStmt = $this->db->prepare('SELECT * FROM crm_invoice_items WHERE invoice_id = ?');
            $itemStmt->execute([$parent['id']]);
            $items = $itemStmt->fetchAll();

            $dueDate = date('Y-m-d', strtotime($nextDate . ' +' .
                (strtotime($parent['due_date']) - strtotime($parent['issue_date'])) / 86400 . ' days'));

            $newInvoice = $this->createInvoice($userEmail, [
                'client_id' => $parent['client_id'],
                'issue_date' => $nextDate,
                'due_date' => $dueDate,
                'tax_rate' => $parent['tax_rate'],
                'discount_amount' => $parent['discount_amount'],
                'currency' => $parent['currency'],
                'notes' => $parent['notes'],
                'internal_notes' => $parent['internal_notes'],
                'is_recurring' => 1,
                'recurrence_interval' => $parent['recurrence_interval'],
                'recurrence_end_date' => $parent['recurrence_end_date'],
                'parent_invoice_id' => $parent['id'],
                'board_card_id' => $parent['board_card_id'],
                'items' => array_map(fn($item) => [
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'tax_rate' => $item['tax_rate'],
                    'sort_order' => $item['sort_order'],
                    'board_card_id' => $item['board_card_id'],
                ], $items),
            ]);

            $created[] = $newInvoice;
        }

        return $created;
    }

    private function calculateNextDate(string $date, ?string $interval): ?string
    {
        if (!$interval) return null;

        $map = [
            'weekly' => '+1 week',
            'monthly' => '+1 month',
            'quarterly' => '+3 months',
            'yearly' => '+1 year',
        ];

        $mod = $map[$interval] ?? null;
        if (!$mod) return null;

        return date('Y-m-d', strtotime($date . ' ' . $mod));
    }

    // =========================================================================
    // PDF Generation
    // =========================================================================

    /**
     * Generate an HTML invoice (can be converted to PDF via wkhtmltopdf or browser print)
     */
    public function generateInvoiceHtml(int $id, string $userEmail): ?string
    {
        $invoice = $this->getInvoice($id, $userEmail);
        if (!$invoice) return null;

        // Get client info
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$invoice['client_id']]);
        $client = $stmt->fetch();

        $clientName = $client ? ($client['name'] ?? $client['domain'] ?? 'Client') : 'Client';
        $items = $invoice['items'] ?? [];

        $itemsHtml = '';
        foreach ($items as $item) {
            $itemsHtml .= "<tr>
                <td style='padding:8px;border-bottom:1px solid #e5e7eb;'>{$item['description']}</td>
                <td style='padding:8px;border-bottom:1px solid #e5e7eb;text-align:center;'>{$item['quantity']} " . ($item['unit'] ?? '') . "</td>
                <td style='padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;'>" . number_format($item['unit_price'], 2) . "</td>
                <td style='padding:8px;border-bottom:1px solid #e5e7eb;text-align:right;'>" . number_format($item['total'], 2) . "</td>
            </tr>";
        }

        $html = "<!DOCTYPE html>
<html><head><meta charset='UTF-8'><title>Invoice {$invoice['invoice_number']}</title>
<style>
    body { font-family: 'Segoe UI', Arial, sans-serif; margin:40px; color:#1f2937; }
    .header { display:flex; justify-content:space-between; margin-bottom:40px; }
    .invoice-title { font-size:28px; font-weight:700; color:#4f46e5; }
    .meta-table td { padding:4px 12px 4px 0; }
    .items-table { width:100%; border-collapse:collapse; margin:24px 0; }
    .items-table th { background:#f3f4f6; padding:10px 8px; text-align:left; font-weight:600; }
    .totals { text-align:right; margin-top:20px; }
    .totals td { padding:4px 0; }
    .total-row { font-size:18px; font-weight:700; color:#4f46e5; }
    .notes { margin-top:30px; padding:16px; background:#f9fafb; border-radius:8px; }
    .status-badge { display:inline-block; padding:4px 12px; border-radius:12px; font-size:12px; font-weight:600; text-transform:uppercase; }
</style></head><body>
<div class='header'>
    <div>
        <div class='invoice-title'>INVOICE</div>
        <div style='margin-top:8px;font-size:20px;font-weight:600;'>{$invoice['invoice_number']}</div>
    </div>
    <div style='text-align:right;'>
        <div style='font-weight:600;'>Bill To:</div>
        <div style='font-size:18px;'>{$clientName}</div>
    </div>
</div>
<table class='meta-table'>
    <tr><td style='font-weight:600;'>Status:</td><td><span class='status-badge'>{$invoice['status']}</span></td></tr>
    <tr><td style='font-weight:600;'>Issue Date:</td><td>{$invoice['issue_date']}</td></tr>
    <tr><td style='font-weight:600;'>Due Date:</td><td>{$invoice['due_date']}</td></tr>
    <tr><td style='font-weight:600;'>Currency:</td><td>{$invoice['currency']}</td></tr>
</table>
<table class='items-table'>
    <thead><tr><th>Description</th><th style='text-align:center;'>Qty</th><th style='text-align:right;'>Unit Price</th><th style='text-align:right;'>Total</th></tr></thead>
    <tbody>{$itemsHtml}</tbody>
</table>
<table class='totals' style='margin-left:auto;'>
    <tr><td style='padding-right:20px;'>Subtotal:</td><td>" . number_format($invoice['subtotal'], 2) . " {$invoice['currency']}</td></tr>"
    . ($invoice['tax_rate'] > 0 ? "<tr><td style='padding-right:20px;'>Tax ({$invoice['tax_rate']}%):</td><td>" . number_format($invoice['tax_amount'], 2) . " {$invoice['currency']}</td></tr>" : '')
    . ($invoice['discount_amount'] > 0 ? "<tr><td style='padding-right:20px;'>Discount:</td><td>-" . number_format($invoice['discount_amount'], 2) . " {$invoice['currency']}</td></tr>" : '')
    . "<tr class='total-row'><td style='padding-right:20px;border-top:2px solid #e5e7eb;padding-top:8px;'>Total:</td><td style='border-top:2px solid #e5e7eb;padding-top:8px;'>" . number_format($invoice['total'], 2) . " {$invoice['currency']}</td></tr>"
    . ($invoice['paid_amount'] > 0 ? "<tr><td style='padding-right:20px;'>Paid:</td><td>" . number_format($invoice['paid_amount'], 2) . " {$invoice['currency']}</td></tr>
    <tr style='font-weight:600;'><td style='padding-right:20px;'>Balance Due:</td><td>" . number_format($invoice['total'] - $invoice['paid_amount'], 2) . " {$invoice['currency']}</td></tr>" : '')
    . "</table>"
    . ($invoice['notes'] ? "<div class='notes'><strong>Notes:</strong><br>{$invoice['notes']}</div>" : '')
    . "</body></html>";

        return $html;
    }

    // =========================================================================
    // Expenses
    // =========================================================================

    /**
     * List expenses with filters
     */
    public function listExpenses(string $userEmail, array $filters = []): array
    {
        $where = ['user_email = ?'];
        $params = [$userEmail];

        if (!empty($filters['client_id'])) {
            $where[] = 'client_id = ?';
            $params[] = (int)$filters['client_id'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'category = ?';
            $params[] = $filters['category'];
        }
        if (!empty($filters['from_date'])) {
            $where[] = 'expense_date >= ?';
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $where[] = 'expense_date <= ?';
            $params[] = $filters['to_date'];
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->prepare("
            SELECT * FROM crm_expenses 
            WHERE {$whereClause}
            ORDER BY expense_date DESC
        ");
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * Create an expense
     */
    public function createExpense(string $userEmail, array $data): array
    {
        $stmt = $this->db->prepare('
            INSERT INTO crm_expenses (client_id, user_email, description, amount, currency, expense_date, category, receipt_drive_file_id, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            (int)$data['client_id'],
            $userEmail,
            $data['description'],
            (float)$data['amount'],
            $data['currency'] ?? 'HUF',
            $data['expense_date'] ?? date('Y-m-d'),
            $data['category'] ?? null,
            $data['receipt_drive_file_id'] ?? null,
            $data['notes'] ?? null,
        ]);

        $id = (int)$this->db->lastInsertId();
        $stmt = $this->db->prepare('SELECT * FROM crm_expenses WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Update an expense
     */
    public function updateExpense(int $id, string $userEmail, array $data): ?array
    {
        $fields = [];
        $params = [];
        $allowed = ['client_id', 'description', 'amount', 'currency', 'expense_date', 'category', 'receipt_drive_file_id', 'notes'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) return null;

        $params[] = $id;
        $params[] = $userEmail;
        $stmt = $this->db->prepare("UPDATE crm_expenses SET " . implode(', ', $fields) . " WHERE id = ? AND user_email = ?");
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) return null;

        $stmt = $this->db->prepare('SELECT * FROM crm_expenses WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * Delete an expense
     */
    public function deleteExpense(int $id, string $userEmail): bool
    {
        $stmt = $this->db->prepare('DELETE FROM crm_expenses WHERE id = ? AND user_email = ?');
        $stmt->execute([$id, $userEmail]);
        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Summary / Stats
    // =========================================================================

    /**
     * Get invoice summary stats for a user
     */
    public function getSummary(string $userEmail, ?int $clientId = null): array
    {
        $where = 'user_email = ?';
        $params = [$userEmail];

        if ($clientId) {
            $where .= ' AND client_id = ?';
            $params[] = $clientId;
        }

        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_invoices,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END), 0) as total_revenue,
                COALESCE(SUM(CASE WHEN status IN ('sent', 'viewed', 'partial', 'overdue') THEN total - paid_amount ELSE 0 END), 0) as outstanding,
                COALESCE(SUM(CASE WHEN status = 'overdue' THEN total - paid_amount ELSE 0 END), 0) as overdue_amount,
                COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count,
                COUNT(CASE WHEN status = 'draft' THEN 1 END) as draft_count
            FROM crm_invoices WHERE {$where}
        ");
        $stmt->execute($params);
        $invoiceStats = $stmt->fetch();

        // Expense total
        $stmt = $this->db->prepare("SELECT COALESCE(SUM(amount), 0) as total_expenses FROM crm_expenses WHERE {$where}");
        $stmt->execute($params);
        $expenseStats = $stmt->fetch();

        return array_merge($invoiceStats, $expenseStats, [
            'net_revenue' => (float)$invoiceStats['total_revenue'] - (float)$expenseStats['total_expenses'],
        ]);
    }
}

