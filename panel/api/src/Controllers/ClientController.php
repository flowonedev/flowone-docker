<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * Client Management Controller
 * 
 * Handles CRUD operations for hosting clients.
 * Only super_admin can access these endpoints.
 */
class ClientController extends BaseController
{
    /**
     * List all clients
     */
    public function index(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $db = $this->container->getDatabase();
            
            // Get filters
            $status = $request->getQuery('status');
            $search = $request->getQuery('search');
            
            $sql = "SELECT c.*, 
                    (SELECT COUNT(*) FROM hosting_domains cd WHERE cd.client_id = c.id) as domain_count,
                    (SELECT COUNT(*) FROM hosting_subscriptions cs WHERE cs.client_id = c.id AND cs.status = 'active') as active_subscriptions
                    FROM hosting_clients c WHERE 1=1";
            $params = [];
            
            if ($status) {
                $sql .= " AND c.status = ?";
                $params[] = $status;
            }
            
            if ($search) {
                $sql .= " AND (c.name LIKE ? OR c.email LIKE ? OR c.company LIKE ?)";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }
            
            $sql .= " ORDER BY c.name ASC";
            
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $clients = $stmt->fetchAll();
            
            return Response::success([
                'clients' => $clients,
                'count' => count($clients),
            ]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get single client details
     */
    public function show(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("SELECT * FROM hosting_clients WHERE id = ?");
            $stmt->execute([$id]);
            $client = $stmt->fetch();
            
            if (!$client) {
                return Response::notFound('Client not found');
            }
            
            // Get domains
            $stmt = $db->prepare("SELECT domain FROM hosting_domains WHERE client_id = ? ORDER BY domain");
            $stmt->execute([$id]);
            $client['domains'] = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            // Get subscriptions
            $stmt = $db->prepare("SELECT * FROM hosting_subscriptions WHERE client_id = ? ORDER BY next_due_date ASC");
            $stmt->execute([$id]);
            $client['subscriptions'] = $stmt->fetchAll();
            
            // Get recent payments
            $stmt = $db->prepare("SELECT * FROM hosting_payments WHERE client_id = ? ORDER BY payment_date DESC LIMIT 10");
            $stmt->execute([$id]);
            $client['recent_payments'] = $stmt->fetchAll();
            
            return Response::success(['client' => $client]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Create a new client
     */
    public function create(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        $validation = $this->validateRequired($request, ['name', 'email']);
        if ($validation) return $validation;
        
        $name = trim($request->input('name'));
        $email = strtolower(trim($request->input('email')));
        $phone = $request->input('phone');
        $company = $request->input('company');
        $address = $request->input('address');
        $notes = $request->input('notes');
        $status = $request->input('status', 'active');
        
        // Validate email format
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::validationError(['email' => 'Invalid email format']);
        }
        
        try {
            $db = $this->container->getDatabase();
            
            // Create client
            $stmt = $db->prepare("
                INSERT INTO hosting_clients (name, email, phone, company, address, notes, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $email, $phone, $company, $address, $notes, $status]);
            
            $clientId = $db->lastInsertId();
            
            // Assign domains if provided
            $domains = $request->input('domains', []);
            if (!empty($domains) && is_array($domains)) {
                $stmt = $db->prepare("INSERT INTO hosting_domains (client_id, domain) VALUES (?, ?)");
                foreach ($domains as $domain) {
                    $stmt->execute([$clientId, $domain]);
                }
            }
            
            $this->logAction('client.create', $name, 'success', [
                'email' => $email,
                'domains_assigned' => count($domains),
            ]);
            
            return Response::success([
                'id' => $clientId,
                'name' => $name,
            ], 'Client created successfully', 201);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update a client
     */
    public function update(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            // Check client exists
            $stmt = $db->prepare("SELECT * FROM hosting_clients WHERE id = ?");
            $stmt->execute([$id]);
            $client = $stmt->fetch();
            
            if (!$client) {
                return Response::notFound('Client not found');
            }
            
            // Build update query
            $updates = [];
            $params = [];
            
            $fields = ['name', 'email', 'phone', 'company', 'address', 'notes', 'status'];
            
            foreach ($fields as $field) {
                if ($request->has($field)) {
                    $value = $request->input($field);
                    
                    // Validate email if updating
                    if ($field === 'email' && $value) {
                        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                            return Response::validationError(['email' => 'Invalid email format']);
                        }
                        $value = strtolower(trim($value));
                    }
                    
                    $updates[] = "{$field} = ?";
                    $params[] = $value;
                }
            }
            
            if (!empty($updates)) {
                $params[] = $id;
                $sql = "UPDATE hosting_clients SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
            }
            
            // Update domains if provided
            if ($request->has('domains')) {
                $domains = $request->input('domains', []);
                
                // Remove existing domains
                $stmt = $db->prepare("DELETE FROM hosting_domains WHERE client_id = ?");
                $stmt->execute([$id]);
                
                // Add new domains
                if (!empty($domains) && is_array($domains)) {
                    $stmt = $db->prepare("INSERT INTO hosting_domains (client_id, domain) VALUES (?, ?)");
                    foreach ($domains as $domain) {
                        $stmt->execute([$id, $domain]);
                    }
                }
            }
            
            $this->logAction('client.update', $client['name'], 'success', [
                'fields_updated' => array_keys($request->all()),
            ]);
            
            return Response::success(null, 'Client updated successfully');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Delete a client
     */
    public function delete(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            // Check client exists
            $stmt = $db->prepare("SELECT name FROM hosting_clients WHERE id = ?");
            $stmt->execute([$id]);
            $client = $stmt->fetch();
            
            if (!$client) {
                return Response::notFound('Client not found');
            }
            
            // Delete client (cascades to domains, subscriptions, payments)
            $stmt = $db->prepare("DELETE FROM hosting_clients WHERE id = ?");
            $stmt->execute([$id]);
            
            $this->logAction('client.delete', $client['name'], 'success');
            
            return Response::success(null, 'Client deleted successfully');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get domains for a client
     */
    public function getDomains(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            $stmt = $db->prepare("SELECT domain FROM hosting_domains WHERE client_id = ? ORDER BY domain");
            $stmt->execute([$id]);
            $domains = $stmt->fetchAll(\PDO::FETCH_COLUMN);
            
            return Response::success(['domains' => $domains]);
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Update domains for a client
     */
    public function updateDomains(Request $request): Response
    {
        $roleCheck = $this->requireSuperAdmin();
        if ($roleCheck) return $roleCheck;
        
        try {
            $id = (int)$request->getParam('id');
            $db = $this->container->getDatabase();
            
            // Check client exists
            $stmt = $db->prepare("SELECT name FROM hosting_clients WHERE id = ?");
            $stmt->execute([$id]);
            $client = $stmt->fetch();
            
            if (!$client) {
                return Response::notFound('Client not found');
            }
            
            $domains = $request->input('domains', []);
            
            // Remove existing domains
            $stmt = $db->prepare("DELETE FROM hosting_domains WHERE client_id = ?");
            $stmt->execute([$id]);
            
            // Add new domains
            if (!empty($domains) && is_array($domains)) {
                $stmt = $db->prepare("INSERT INTO hosting_domains (client_id, domain) VALUES (?, ?)");
                foreach ($domains as $domain) {
                    $stmt->execute([$id, $domain]);
                }
            }
            
            $this->logAction('client.domains.update', $client['name'], 'success', [
                'domains_count' => count($domains),
            ]);
            
            return Response::success([
                'domains_count' => count($domains),
            ], 'Domains updated successfully');
        } catch (\PDOException $e) {
            return Response::error('Database error: ' . $e->getMessage(), 500);
        }
    }
}

