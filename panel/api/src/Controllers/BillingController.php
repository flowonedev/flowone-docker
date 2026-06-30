<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * Billing Controller
 * 
 * Handles subscriptions, payments, and billing reports.
 * Only super_admin can access these endpoints.
 */
class BillingController extends BaseController
{
    // ==========================================
    // Subscriptions
    // ==========================================

    /**
     * List all subscriptions
     */
    public function subscriptions(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $db = $this->container->getDatabase();
            
            // Get filters
            $status = $request->getQuery('status');
            $clientId = $request->getQuery('client_id');
            
            $sql = "SELECT cs.*, c.name as client_name, c.email as client_email
                    FROM hosting_subscriptions cs
                    JOIN hosting_clients c ON cs.client_id = c.id
                    WHERE 1=1";
            $params = [];
            
            if ($status) {
                $sql .= " AND cs.status = ?";
                $params[] = $status;
            }
            
            if ($clientId) {
                $sql .= " AND cs.client_id = ?";
                $params[] = $clientId;
            }
            
            $sql .= " ORDER BY cs.next_due_date ASC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $subscriptions = $stmt->fetchAll();
            
            return Response::success([
                'subscriptions' => $subscriptions,
                'count' => count($subscriptions),
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get single subscription
     */
    public function showSubscription(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("
                SELECT cs.*, c.name as client_name, c.email as client_email
                FROM hosting_subscriptions cs
                JOIN hosting_clients c ON cs.client_id = c.id
                WHERE cs.id = ?
            ");
            $stmt->execute([$id]);
            $subscription = $stmt->fetch();
            
            if (!$subscription) {
                return Response::notFound('Subscription not found');
            }
            
            // Get payments for this subscription
            $stmt = $db->prepare("SELECT * FROM hosting_payments WHERE subscription_id = ? ORDER BY payment_date DESC");
            $stmt->execute([$id]);
            $subscription['payments'] = $stmt->fetchAll();
            
            return Response::success(['subscription' => $subscription]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a subscription
     */
    public function createSubscription(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $validation = $this->validateRequired($request, ['client_id', 'plan_name', 'amount', 'start_date']);
        if ($validation) return $validation;
        
        $clientId = (int)$request->input('client_id');
        $planName = trim($request->input('plan_name'));
        $amount = (float)$request->input('amount');
        $currency = $request->input('currency', 'HUF');
        $billingCycle = $request->input('billing_cycle', 'yearly');
        $startDate = $request->input('start_date');
        $notes = $request->input('notes');
        
        try {
            $db = $this->container->getDatabase();
            
            // Check client exists
            $stmt = $db->prepare("SELECT name FROM hosting_clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $client = $stmt->fetch();
            
            if (!$client) {
                return Response::error('Client not found', 404);
            }
            
            // Calculate next due date
            $nextDueDate = $this->calculateNextDueDate($startDate, $billingCycle);
            
            $stmt = $db->prepare("
                INSERT INTO hosting_subscriptions 
                (client_id, plan_name, amount, currency, billing_cycle, start_date, next_due_date, status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)
            ");
            $stmt->execute([
                $clientId, $planName, $amount, $currency, $billingCycle, $startDate, $nextDueDate, $notes
            ]);
            
            $subscriptionId = $db->lastInsertId();
            
            $this->logAction('subscription.create', $client['name'], 'success', [
                'plan' => $planName,
                'amount' => $amount,
                'currency' => $currency,
            ]);
            
            return Response::success([
                'id' => $subscriptionId,
                'next_due_date' => $nextDueDate,
            ], 'Subscription created successfully', 201);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update a subscription
     */
    public function updateSubscription(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            // Check subscription exists
            $stmt = $db->prepare("
                SELECT cs.*, c.name as client_name 
                FROM hosting_subscriptions cs 
                JOIN hosting_clients c ON cs.client_id = c.id 
                WHERE cs.id = ?
            ");
            $stmt->execute([$id]);
            $subscription = $stmt->fetch();
            
            if (!$subscription) {
                return Response::notFound('Subscription not found');
            }
            
            // Build update query
            $updates = [];
            $params = [];
            
            $fields = ['plan_name', 'amount', 'currency', 'billing_cycle', 'start_date', 'next_due_date', 'status', 'notes'];
            
            foreach ($fields as $field) {
                if ($request->has($field)) {
                    $updates[] = "{$field} = ?";
                    $params[] = $request->input($field);
                }
            }
            
            if (!empty($updates)) {
                $params[] = $id;
                $sql = "UPDATE hosting_subscriptions SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            }
            
            $this->logAction('subscription.update', $subscription['client_name'], 'success', [
                'subscription_id' => $id,
                'fields_updated' => array_keys($request->all()),
            ]);
            
            return Response::success(null, 'Subscription updated successfully');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a subscription
     */
    public function deleteSubscription(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            // Check subscription exists
            $stmt = $db->prepare("
                SELECT cs.*, c.name as client_name 
                FROM hosting_subscriptions cs 
                JOIN hosting_clients c ON cs.client_id = c.id 
                WHERE cs.id = ?
            ");
            $stmt->execute([$id]);
            $subscription = $stmt->fetch();
            
            if (!$subscription) {
                return Response::notFound('Subscription not found');
            }
            
            $stmt = $db->prepare("DELETE FROM hosting_subscriptions WHERE id = ?");
            $stmt->execute([$id]);
            
            $this->logAction('subscription.delete', $subscription['client_name'], 'success', [
                'plan' => $subscription['plan_name'],
            ]);
            
            return Response::success(null, 'Subscription deleted successfully');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ==========================================
    // Payments
    // ==========================================

    /**
     * List all payments
     */
    public function payments(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $db = $this->container->getDatabase();
            
            // Get filters
            $clientId = $request->getQuery('client_id');
            $subscriptionId = $request->getQuery('subscription_id');
            $fromDate = $request->getQuery('from_date');
            $toDate = $request->getQuery('to_date');
            
            $sql = "SELECT p.*, c.name as client_name, c.email as client_email,
                    cs.plan_name as subscription_plan
                    FROM hosting_payments p
                    JOIN hosting_clients c ON p.client_id = c.id
                    LEFT JOIN hosting_subscriptions cs ON p.subscription_id = cs.id
                    WHERE 1=1";
            $params = [];
            
            if ($clientId) {
                $sql .= " AND p.client_id = ?";
                $params[] = $clientId;
            }
            
            if ($subscriptionId) {
                $sql .= " AND p.subscription_id = ?";
                $params[] = $subscriptionId;
            }
            
            if ($fromDate) {
                $sql .= " AND p.payment_date >= ?";
                $params[] = $fromDate;
            }
            
            if ($toDate) {
                $sql .= " AND p.payment_date <= ?";
                $params[] = $toDate;
            }
            
            $sql .= " ORDER BY p.payment_date DESC";
            
            // Apply limit
            $limit = min(500, (int)$request->getQuery('limit', 100));
            $sql .= " LIMIT {$limit}";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $payments = $stmt->fetchAll();
            
            // Calculate totals
            $total = array_reduce($payments, function($carry, $payment) {
                return $carry + (float)$payment['amount'];
            }, 0);
            
            return Response::success([
                'payments' => $payments,
                'count' => count($payments),
                'total' => $total,
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Record a payment
     */
    public function recordPayment(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $validation = $this->validateRequired($request, ['client_id', 'amount', 'payment_date']);
        if ($validation) return $validation;
        
        $clientId = (int)$request->input('client_id');
        $subscriptionId = $request->input('subscription_id') ? (int)$request->input('subscription_id') : null;
        $amount = (float)$request->input('amount');
        $currency = $request->input('currency', 'HUF');
        $paymentDate = $request->input('payment_date');
        $paymentMethod = $request->input('payment_method');
        $transactionRef = $request->input('transaction_ref');
        $notes = $request->input('notes');
        
        try {
            $db = $this->container->getDatabase();
            
            // Check client exists
            $stmt = $db->prepare("SELECT name FROM hosting_clients WHERE id = ?");
            $stmt->execute([$clientId]);
            $client = $stmt->fetch();
            
            if (!$client) {
                return Response::error('Client not found', 404);
            }
            
            $stmt = $db->prepare("
                INSERT INTO hosting_payments 
                (client_id, subscription_id, amount, currency, payment_date, payment_method, transaction_ref, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $clientId, $subscriptionId, $amount, $currency, $paymentDate, $paymentMethod, $transactionRef, $notes
            ]);
            
            $paymentId = $db->lastInsertId();
            
            // If subscription is provided, update next due date
            if ($subscriptionId) {
                $stmt = $db->prepare("SELECT billing_cycle, next_due_date FROM hosting_subscriptions WHERE id = ?");
                $stmt->execute([$subscriptionId]);
                $subscription = $stmt->fetch();
                
                if ($subscription) {
                    $newDueDate = $this->calculateNextDueDate($subscription['next_due_date'], $subscription['billing_cycle']);
                    $stmt = $db->prepare("UPDATE hosting_subscriptions SET next_due_date = ?, status = 'active' WHERE id = ?");
                    $stmt->execute([$newDueDate, $subscriptionId]);
                }
            }
            
            $this->logAction('payment.record', $client['name'], 'success', [
                'amount' => $amount,
                'currency' => $currency,
                'subscription_id' => $subscriptionId,
            ]);
            
            return Response::success([
                'id' => $paymentId,
            ], 'Payment recorded successfully', 201);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a payment
     */
    public function deletePayment(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            // Check payment exists
            $stmt = $db->prepare("
                SELECT p.*, c.name as client_name 
                FROM hosting_payments p 
                JOIN hosting_clients c ON p.client_id = c.id 
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $payment = $stmt->fetch();
            
            if (!$payment) {
                return Response::notFound('Payment not found');
            }
            
            $stmt = $db->prepare("DELETE FROM hosting_payments WHERE id = ?");
            $stmt->execute([$id]);
            
            $this->logAction('payment.delete', $payment['client_name'], 'success', [
                'amount' => $payment['amount'],
            ]);
            
            return Response::success(null, 'Payment deleted successfully');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ==========================================
    // Reports
    // ==========================================

    /**
     * Get upcoming payments (due in next 30 days)
     */
    public function upcoming(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $db = $this->container->getDatabase();
            $days = (int)$request->getQuery('days', 30);
            
            $stmt = $db->prepare("
                SELECT cs.*, c.name as client_name, c.email as client_email
                FROM hosting_subscriptions cs
                JOIN hosting_clients c ON cs.client_id = c.id
                WHERE cs.status = 'active'
                AND cs.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                ORDER BY cs.next_due_date ASC
            ");
            $stmt->execute([$days]);
            $upcoming = $stmt->fetchAll();
            
            // Calculate total amount due
            $totalDue = array_reduce($upcoming, function($carry, $sub) {
                return $carry + (float)$sub['amount'];
            }, 0);
            
            return Response::success([
                'subscriptions' => $upcoming,
                'count' => count($upcoming),
                'total_due' => $totalDue,
                'period_days' => $days,
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get overdue payments
     */
    public function overdue(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("
                SELECT cs.*, c.name as client_name, c.email as client_email,
                DATEDIFF(CURDATE(), cs.next_due_date) as days_overdue
                FROM hosting_subscriptions cs
                JOIN hosting_clients c ON cs.client_id = c.id
                WHERE cs.status = 'active'
                AND cs.next_due_date < CURDATE()
                ORDER BY cs.next_due_date ASC
            ");
            $stmt->execute();
            $overdue = $stmt->fetchAll();
            
            // Calculate total overdue
            $totalOverdue = array_reduce($overdue, function($carry, $sub) {
                return $carry + (float)$sub['amount'];
            }, 0);
            
            return Response::success([
                'subscriptions' => $overdue,
                'count' => count($overdue),
                'total_overdue' => $totalOverdue,
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get billing statistics
     */
    public function stats(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $db = $this->container->getDatabase();
            
            // Total clients
            $stmt = $db->query("SELECT COUNT(*) FROM hosting_clients WHERE status = 'active'");
            $totalClients = $stmt->fetchColumn();
            
            // Total active subscriptions
            $stmt = $db->query("SELECT COUNT(*) FROM hosting_subscriptions WHERE status = 'active'");
            $activeSubscriptions = $stmt->fetchColumn();
            
            // Monthly recurring revenue (MRR) - convert yearly to monthly
            $stmt = $db->query("
                SELECT SUM(CASE 
                    WHEN billing_cycle = 'monthly' THEN amount 
                    WHEN billing_cycle = 'yearly' THEN amount / 12 
                    ELSE 0 
                END) as mrr
                FROM hosting_subscriptions 
                WHERE status = 'active'
            ");
            $mrr = (float)$stmt->fetchColumn();
            
            // Annual recurring revenue
            $arr = $mrr * 12;
            
            // Revenue this month
            $stmt = $db->query("
                SELECT COALESCE(SUM(amount), 0) 
                FROM hosting_payments 
                WHERE YEAR(payment_date) = YEAR(CURDATE()) 
                AND MONTH(payment_date) = MONTH(CURDATE())
            ");
            $revenueThisMonth = (float)$stmt->fetchColumn();
            
            // Revenue this year
            $stmt = $db->query("
                SELECT COALESCE(SUM(amount), 0) 
                FROM hosting_payments 
                WHERE YEAR(payment_date) = YEAR(CURDATE())
            ");
            $revenueThisYear = (float)$stmt->fetchColumn();
            
            // Overdue count
            $stmt = $db->query("
                SELECT COUNT(*) FROM hosting_subscriptions 
                WHERE status = 'active' AND next_due_date < CURDATE()
            ");
            $overdueCount = $stmt->fetchColumn();
            
            // Upcoming (next 30 days) count
            $stmt = $db->query("
                SELECT COUNT(*) FROM hosting_subscriptions 
                WHERE status = 'active' 
                AND next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            ");
            $upcomingCount = $stmt->fetchColumn();
            
            return Response::success([
                'clients' => (int)$totalClients,
                'active_subscriptions' => (int)$activeSubscriptions,
                'mrr' => round($mrr, 2),
                'arr' => round($arr, 2),
                'revenue_this_month' => round($revenueThisMonth, 2),
                'revenue_this_year' => round($revenueThisYear, 2),
                'overdue_count' => (int)$overdueCount,
                'upcoming_count' => (int)$upcomingCount,
                'currency' => 'HUF',
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ==========================================
    // Settings
    // ==========================================

    /**
     * Get billing settings
     */
    public function getSettings(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $db = $this->container->getDatabase();
            
            $stmt = $db->query("SELECT setting_key, setting_value FROM billing_settings");
            $rows = $stmt->fetchAll();
            
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            return Response::success(['settings' => $settings]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update billing settings
     */
    public function updateSettings(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $db = $this->container->getDatabase();
            $settings = $request->input('settings', []);
            
            $stmt = $db->prepare("
                INSERT INTO billing_settings (setting_key, setting_value) 
                VALUES (?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
            ");
            
            foreach ($settings as $key => $value) {
                $stmt->execute([$key, $value]);
            }
            
            $this->logAction('billing.settings.update', 'billing', 'success', [
                'keys_updated' => array_keys($settings),
            ]);
            
            return Response::success(null, 'Settings updated successfully');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    // ==========================================
    // Helpers
    // ==========================================

    /**
     * Calculate next due date based on billing cycle
     */
    private function calculateNextDueDate(string $fromDate, string $billingCycle): string
    {
        $date = new \DateTime($fromDate);
        
        switch ($billingCycle) {
            case 'monthly':
                $date->modify('+1 month');
                break;
            case 'yearly':
            default:
                $date->modify('+1 year');
                break;
        }
        
        return $date->format('Y-m-d');
    }
}

