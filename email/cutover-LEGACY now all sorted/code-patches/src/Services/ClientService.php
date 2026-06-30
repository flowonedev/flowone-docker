<?php

namespace Webmail\Services;

/**
 * ClientService - Client Overview feature
 * 
 * Derives client entities from email domains and aggregates work context
 * from emails, boards, drive, calendar, and todos.
 * 
 * Key principles:
 * - Clients are derived, not managed (no manual data entry)
 * - Status is computed automatically based on activity
 * - Read-first, clarity-focused design
 */
class ClientService
{
    private \PDO $db;
    private array $config;
    
    // Domains to exclude from client creation (common services)
    private array $excludedDomains = [
        'gmail.com', 'googlemail.com', 'yahoo.com', 'hotmail.com', 
        'outlook.com', 'live.com', 'msn.com', 'icloud.com', 'me.com',
        'aol.com', 'mail.com', 'protonmail.com', 'proton.me',
        'yandex.com', 'gmx.com', 'zoho.com'
    ];
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        $this->ensureTablesExist();
    }
    
    /**
     * Log an activity to the activity_log table
     */
    public function logActivity(
        string $userEmail,
        string $actionType,
        string $entityType,
        ?int $entityId = null,
        ?string $entityName = null,
        ?int $boardId = null,
        ?int $clientId = null,
        ?array $metadata = null
    ): bool {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO activity_log (user_email, action_type, entity_type, entity_id, entity_name, board_id, client_id, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                strtolower($userEmail),
                $actionType,
                $entityType,
                $entityId,
                $entityName,
                $boardId,
                $clientId,
                $metadata ? json_encode($metadata) : null
            ]);
            return true;
        } catch (\PDOException $e) {
            error_log("ClientService logActivity error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Helper to add a column if it doesn't exist
     */
    private function addColumnIfNotExists(string $table, string $column, string $definition): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as cnt FROM information_schema.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            $result = $stmt->fetch();
            
            if ($result && (int)$result['cnt'] === 0) {
                $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                error_log("ClientService: Added column {$column} to {$table}");
            }
        } catch (\PDOException $e) {
            error_log("ClientService addColumnIfNotExists error ({$table}.{$column}): " . $e->getMessage());
        }
    }
    
    private function ensureTablesExist(): void
    {
        // Create tables without foreign keys to avoid constraint issues
        // Data integrity is handled by application logic
        
        try {
            // Create clients table
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS clients (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    domain VARCHAR(255) NOT NULL,
                    display_name VARCHAR(255) DEFAULT NULL,
                    status ENUM('active', 'waiting', 'attention') DEFAULT 'active',
                    last_activity_at DATETIME DEFAULT NULL,
                    last_email_direction ENUM('inbound', 'outbound') DEFAULT NULL,
                    last_outbound_at DATETIME DEFAULT NULL,
                    last_inbound_at DATETIME DEFAULT NULL,
                    open_task_count INT UNSIGNED DEFAULT 0,
                    overdue_task_count INT UNSIGNED DEFAULT 0,
                    next_deadline DATE DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_email (user_email),
                    INDEX idx_domain (domain),
                    INDEX idx_status (status),
                    INDEX idx_last_activity (last_activity_at),
                    UNIQUE KEY unique_user_domain (user_email, domain)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\PDOException $e) {
            // Table might already exist with different structure, that's OK
            error_log("ClientService clients table: " . $e->getMessage());
        }
        
        // Migration: Add last_outbound_at and last_inbound_at columns if not exists
        try {
            $this->db->exec("ALTER TABLE clients ADD COLUMN last_outbound_at DATETIME DEFAULT NULL AFTER last_email_direction");
        } catch (\PDOException $e) { /* Column might already exist */ }
        
        try {
            $this->db->exec("ALTER TABLE clients ADD COLUMN last_inbound_at DATETIME DEFAULT NULL AFTER last_outbound_at");
        } catch (\PDOException $e) { /* Column might already exist */ }
        
        // Migration: Add drive_folder_id column for linking Drive folders to clients
        try {
            $this->db->exec("ALTER TABLE clients ADD COLUMN drive_folder_id INT UNSIGNED DEFAULT NULL AFTER next_deadline");
        } catch (\PDOException $e) { /* Column might already exist */ }
        
        // Migration: Add phone, address, notes fields
        try {
            $this->db->exec("ALTER TABLE clients ADD COLUMN phone VARCHAR(50) DEFAULT NULL AFTER display_name");
        } catch (\PDOException $e) { /* Column might already exist */ }
        
        try {
            $this->db->exec("ALTER TABLE clients ADD COLUMN address TEXT DEFAULT NULL AFTER phone");
        } catch (\PDOException $e) { /* Column might already exist */ }
        
        try {
            $this->db->exec("ALTER TABLE clients ADD COLUMN notes TEXT DEFAULT NULL AFTER address");
        } catch (\PDOException $e) { /* Column might already exist */ }
        
        // Migration: Add payment terms
        try {
            $this->db->exec("ALTER TABLE clients ADD COLUMN payment_terms_days INT DEFAULT 30 AFTER notes");
        } catch (\PDOException $e) { /* Column might already exist */ }
        
        // Migration: Add associated account fields
        try {
            $this->db->exec("ALTER TABLE clients ADD COLUMN is_associated TINYINT(1) DEFAULT 0 AFTER payment_terms_days");
        } catch (\PDOException $e) { /* Column might already exist */ }
        
        try {
            $this->db->exec("ALTER TABLE clients ADD COLUMN associated_with_client_id INT UNSIGNED DEFAULT NULL AFTER is_associated");
        } catch (\PDOException $e) { /* Column might already exist */ }
        
        // Migration: Add company/billing detail fields
        $this->addColumnIfNotExists('clients', 'billing_name', 'VARCHAR(255) DEFAULT NULL AFTER associated_with_client_id');
        $this->addColumnIfNotExists('clients', 'billing_tax_id', 'VARCHAR(50) DEFAULT NULL AFTER billing_name');
        $this->addColumnIfNotExists('clients', 'billing_eu_tax_id', 'VARCHAR(50) DEFAULT NULL AFTER billing_tax_id');
        $this->addColumnIfNotExists('clients', 'billing_address', 'VARCHAR(500) DEFAULT NULL AFTER billing_eu_tax_id');
        $this->addColumnIfNotExists('clients', 'billing_city', 'VARCHAR(255) DEFAULT NULL AFTER billing_address');
        $this->addColumnIfNotExists('clients', 'billing_zip', 'VARCHAR(20) DEFAULT NULL AFTER billing_city');
        $this->addColumnIfNotExists('clients', 'billing_country', 'VARCHAR(5) DEFAULT \'HU\' AFTER billing_zip');
        $this->addColumnIfNotExists('clients', 'billing_bank_account', 'VARCHAR(100) DEFAULT NULL AFTER billing_country');
        
        try {
            // Create client_contacts table with all columns (including phone and position)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS client_contacts (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    client_id INT UNSIGNED NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    name VARCHAR(255) DEFAULT NULL,
                    phone VARCHAR(50) DEFAULT NULL,
                    position VARCHAR(100) DEFAULT NULL,
                    last_email_at DATETIME DEFAULT NULL,
                    email_count INT UNSIGNED DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_client_id (client_id),
                    INDEX idx_email (email),
                    UNIQUE KEY unique_client_email (client_id, email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\PDOException $e) {
            error_log("ClientService client_contacts table: " . $e->getMessage());
        }
        
        // Migration: Add phone and position columns to existing client_contacts tables
        $this->addColumnIfNotExists('client_contacts', 'phone', 'VARCHAR(50) DEFAULT NULL AFTER name');
        $this->addColumnIfNotExists('client_contacts', 'position', 'VARCHAR(100) DEFAULT NULL AFTER phone');
        
        try {
            // Create client_boards linking table (no FK for compatibility)
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS client_boards (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    client_id INT UNSIGNED NOT NULL,
                    board_id INT UNSIGNED NOT NULL,
                    linked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_client_board (client_id, board_id),
                    INDEX idx_client_id (client_id),
                    INDEX idx_board_id (board_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\PDOException $e) {
            error_log("ClientService client_boards table: " . $e->getMessage());
        }
        
        // Domain aliases table - tracks merged domains so they don't get re-created
        try {
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS client_domain_aliases (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    alias_domain VARCHAR(255) NOT NULL COMMENT 'The domain/email that was merged away',
                    client_id INT UNSIGNED NOT NULL COMMENT 'The primary client this alias points to',
                    merged_from_name VARCHAR(255) DEFAULT NULL COMMENT 'Original display name before merge',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_alias (user_email, alias_domain),
                    INDEX idx_client_id (client_id),
                    INDEX idx_user_email (user_email)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\PDOException $e) {
            error_log("ClientService client_domain_aliases table: " . $e->getMessage());
        }
    }
    
    /**
     * Extract domain from email address
     */
    public function extractDomain(string $email): ?string
    {
        $email = strtolower(trim($email));
        $parts = explode('@', $email);
        
        if (count($parts) !== 2 || empty($parts[1])) {
            return null;
        }
        
        return $parts[1];
    }
    
    /**
     * Check if domain is a generic email provider (Gmail, Yahoo, etc.)
     * These should be treated as individual clients per email address.
     * Accepts both bare domains (gmail.com) and full emails (user@gmail.com).
     */
    public function isGenericDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        
        // If it's a full email address, extract just the domain part
        if (strpos($domain, '@') !== false) {
            $domain = substr($domain, strpos($domain, '@') + 1);
        }
        
        return in_array($domain, $this->excludedDomains);
    }
    
    /**
     * Get or create a client from an email address
     * - Generic domains (gmail, yahoo) → individual client per email with sender name
     * - Company domains → grouped by domain
     * - Checks domain aliases from previous merges before creating new clients
     */
    public function getOrCreateClient(string $userEmail, string $contactEmail, ?string $contactName = null): ?array
    {
        $userEmail = strtolower($userEmail);
        $contactEmail = strtolower(trim($contactEmail));
        $domain = $this->extractDomain($contactEmail);
        
        if (!$domain) {
            return null;
        }
        
        // For generic domains, use full email as identifier; otherwise use domain
        $isGeneric = $this->isGenericDomain($domain);
        $clientIdentifier = $isGeneric ? $contactEmail : $domain;
        
        // Check if client exists directly
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE user_email = ? AND domain = ?');
        $stmt->execute([$userEmail, $clientIdentifier]);
        $client = $stmt->fetch();
        
        // If not found, check if this domain/email was merged into another client
        if (!$client) {
            $client = $this->resolveAlias($userEmail, $clientIdentifier);
        }
        
        if (!$client) {
            // Create new client
            if ($isGeneric) {
                // For generic emails, use sender name as display name
                $displayName = $contactName ?: $this->extractNameFromEmail($contactEmail);
            } else {
                // For company domains, generate from domain
                $displayName = $this->generateDisplayName($domain);
            }
            
            $stmt = $this->db->prepare('
                INSERT INTO clients (user_email, domain, display_name, last_activity_at)
                VALUES (?, ?, ?, NOW())
            ');
            $stmt->execute([$userEmail, $clientIdentifier, $displayName]);
            
            $clientId = (int)$this->db->lastInsertId();
            $client = $this->getClient($userEmail, $clientId);
        } else if ($isGeneric && $contactName && empty($client['display_name'])) {
            // Update display name if we now have a name for a generic email client
            $stmt = $this->db->prepare('UPDATE clients SET display_name = ? WHERE id = ?');
            $stmt->execute([$contactName, $client['id']]);
            $client['display_name'] = $contactName;
        }
        
        // Add/update contact - always add the email as a contact
        // For generic domains: this is the only contact
        // For company domains: tracks individual contacts from that company
        if ($client) {
            $this->addContact($client['id'], $contactEmail, $contactName);
        }
        
        return $client;
    }
    
    /**
     * Resolve a domain/email alias to the primary client it was merged into
     */
    private function resolveAlias(string $userEmail, string $aliasDomain): ?array
    {
        try {
            $stmt = $this->db->prepare('
                SELECT c.* FROM client_domain_aliases cda
                JOIN clients c ON c.id = cda.client_id AND c.user_email = cda.user_email
                WHERE cda.user_email = ? AND cda.alias_domain = ?
                LIMIT 1
            ');
            $stmt->execute([$userEmail, $aliasDomain]);
            $client = $stmt->fetch();
            
            if ($client) {
                return $this->getClient($userEmail, $client['id']);
            }
        } catch (\PDOException $e) {
            // Table might not exist yet on first run
            error_log("ClientService resolveAlias error: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Extract a readable name from email address
     * john.doe@gmail.com → "John Doe"
     */
    private function extractNameFromEmail(string $email): string
    {
        $parts = explode('@', $email);
        $localPart = $parts[0];
        
        // Replace dots, underscores, numbers with spaces
        $name = preg_replace('/[._0-9]+/', ' ', $localPart);
        
        // Title case
        $name = ucwords(trim($name));
        
        return $name ?: $localPart;
    }
    
    /**
     * Generate display name from domain
     */
    private function generateDisplayName(string $domain): string
    {
        // Remove TLD and capitalize
        $parts = explode('.', $domain);
        $name = $parts[0];
        return ucfirst($name);
    }
    
    /**
     * Add or update a contact for a client
     */
    public function addContact(int $clientId, string $email, ?string $name = null, ?string $phone = null, ?string $position = null): ?array
    {
        $email = strtolower(trim($email));
        
        // Log what we're trying to save
        error_log("ClientService addContact: clientId=$clientId, email=$email, name=" . ($name ?? 'NULL') . ", phone=" . ($phone ?? 'NULL') . ", position=" . ($position ?? 'NULL'));
        
        try {
            // First check if contact already exists
            $checkStmt = $this->db->prepare('SELECT id FROM client_contacts WHERE client_id = ? AND email = ?');
            $checkStmt->execute([$clientId, $email]);
            $existing = $checkStmt->fetch();
            
            if ($existing) {
                // Update existing contact
                $updateFields = [];
                $updateParams = [];
                
                if ($name !== null) {
                    $updateFields[] = 'name = ?';
                    $updateParams[] = $name;
                }
                if ($phone !== null) {
                    $updateFields[] = 'phone = ?';
                    $updateParams[] = $phone;
                }
                if ($position !== null) {
                    $updateFields[] = 'position = ?';
                    $updateParams[] = $position;
                }
                
                $updateFields[] = 'last_email_at = NOW()';
                $updateFields[] = 'email_count = email_count + 1';
                
                $updateParams[] = (int)$existing['id'];
                $updateParams[] = $clientId;
                
                $stmt = $this->db->prepare('
                    UPDATE client_contacts 
                    SET ' . implode(', ', $updateFields) . '
                    WHERE id = ? AND client_id = ?
                ');
                $stmt->execute($updateParams);
                
                error_log("ClientService addContact: Updated existing contact id=" . $existing['id']);
                $result = $this->getContact($clientId, (int)$existing['id']);
                error_log("ClientService addContact: Returning contact: " . json_encode($result));
                return $result;
            } else {
                // Insert new contact
                $stmt = $this->db->prepare('
                    INSERT INTO client_contacts (client_id, email, name, phone, position, last_email_at, email_count)
                    VALUES (?, ?, ?, ?, ?, NOW(), 1)
                ');
                $stmt->execute([$clientId, $email, $name, $phone, $position]);
                
                $contactId = (int)$this->db->lastInsertId();
                error_log("ClientService addContact: Inserted new contact id=" . $contactId);
                $result = $this->getContact($clientId, $contactId);
                error_log("ClientService addContact: Returning contact: " . json_encode($result));
                return $result;
            }
        } catch (\PDOException $e) {
            error_log("ClientService addContact error: " . $e->getMessage());
            throw $e; // Re-throw to get better error message in controller
        }
    }
    
    /**
     * Update a contact
     */
    public function updateContact(int $clientId, int $contactId, array $data, ?string $userEmail = null): ?array
    {
        // Log what we're receiving
        error_log("ClientService updateContact: clientId=$clientId, contactId=$contactId, data=" . json_encode($data));
        
        try {
            // Get current contact for comparison
            $currentContact = $this->getContact($clientId, $contactId);
            
            $updates = [];
            $params = [];
            
            // Handle name - convert empty string to null
            if (array_key_exists('name', $data)) {
                $updates[] = 'name = ?';
                $params[] = !empty($data['name']) ? $data['name'] : null;
            }
            // Handle phone - convert empty string to null
            if (array_key_exists('phone', $data)) {
                $updates[] = 'phone = ?';
                $params[] = !empty($data['phone']) ? $data['phone'] : null;
            }
            // Handle position - convert empty string to null
            if (array_key_exists('position', $data)) {
                $updates[] = 'position = ?';
                $params[] = !empty($data['position']) ? $data['position'] : null;
            }
            
            if (empty($updates)) {
                error_log("ClientService updateContact: No updates to make");
                return $this->getContact($clientId, $contactId);
            }
            
            $params[] = $contactId;
            $params[] = $clientId;
            
            $sql = 'UPDATE client_contacts SET ' . implode(', ', $updates) . ' WHERE id = ? AND client_id = ?';
            error_log("ClientService updateContact SQL: $sql with params: " . json_encode($params));
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            
            $rowCount = $stmt->rowCount();
            error_log("ClientService updateContact: Rows affected = $rowCount");
            
            $result = $this->getContact($clientId, $contactId);
            error_log("ClientService updateContact: Returning contact = " . json_encode($result));
            
            // Log activity if we have userEmail
            if ($userEmail && $rowCount > 0) {
                $this->logActivity($userEmail, 'contact_updated', 'contact', $contactId, 
                    $result['name'] ?? $result['email'], null, $clientId, [
                        'changes' => $data
                    ]);
            }
            
            return $result;
        } catch (\PDOException $e) {
            error_log("ClientService updateContact error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get a single contact
     */
    public function getContact(int $clientId, int $contactId): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM client_contacts WHERE id = ? AND client_id = ?');
            $stmt->execute([$contactId, $clientId]);
            return $stmt->fetch() ?: null;
        } catch (\PDOException $e) {
            error_log("ClientService getContact error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete a contact from a client
     */
    public function deleteContact(int $clientId, int $contactId): bool
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM client_contacts WHERE id = ? AND client_id = ?');
            $stmt->execute([$contactId, $clientId]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("ClientService deleteContact error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get all contacts for a client
     */
    public function getClientContacts(int $clientId): array
    {
        try {
            $stmt = $this->db->prepare('
                SELECT * FROM client_contacts 
                WHERE client_id = ? 
                ORDER BY email_count DESC, last_email_at DESC
            ');
            $stmt->execute([$clientId]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log("ClientService getClientContacts error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all contacts across all clients for a given user.
     * Returns contacts joined with client name for display.
     */
    public function getAllContacts(string $userEmail): array
    {
        $userEmail = strtolower($userEmail);
        try {
            $stmt = $this->db->prepare('
                SELECT cc.id, cc.email, cc.name, cc.phone, cc.position,
                       c.id as client_id, c.name as client_name
                FROM client_contacts cc
                JOIN clients c ON c.id = cc.client_id
                WHERE c.user_email = ?
                ORDER BY c.name ASC, cc.name ASC
            ');
            $stmt->execute([$userEmail]);
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            error_log("ClientService getAllContacts error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all clients for a user with computed data
     */
    public function getClients(string $userEmail, ?string $status = null, string $sortBy = 'status'): array
    {
        $userEmail = strtolower($userEmail);
        
        $sql = 'SELECT * FROM clients WHERE user_email = ?';
        $params = [$userEmail];
        
        if ($status) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        
        // Sort order: attention first, then waiting, then active
        switch ($sortBy) {
            case 'status':
                $sql .= " ORDER BY FIELD(status, 'attention', 'waiting', 'active'), last_activity_at DESC";
                break;
            case 'activity':
                $sql .= ' ORDER BY last_activity_at DESC';
                break;
            case 'name':
                $sql .= ' ORDER BY display_name ASC';
                break;
            default:
                $sql .= " ORDER BY FIELD(status, 'attention', 'waiting', 'active'), last_activity_at DESC";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $clients = $stmt->fetchAll();
        
        // Add contact count to each client
        foreach ($clients as &$client) {
            $countStmt = $this->db->prepare('SELECT COUNT(*) as count FROM client_contacts WHERE client_id = ?');
            $countStmt->execute([$client['id']]);
            $client['contact_count'] = (int)$countStmt->fetch()['count'];
        }
        
        return $clients;
    }
    
    /**
     * Get a single client by ID
     */
    public function getClient(string $userEmail, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($userEmail), $id]);
        $client = $stmt->fetch();
        
        if (!$client) {
            return null;
        }
        
        // Add contacts from database
        $contactStmt = $this->db->prepare('
            SELECT * FROM client_contacts 
            WHERE client_id = ? 
            ORDER BY email_count DESC, last_email_at DESC
        ');
        $contactStmt->execute([$id]);
        $contacts = $contactStmt->fetchAll();
        
        // If no contacts found, create a primary contact from the domain/display_name
        if (empty($contacts)) {
            $primaryEmail = $this->isGenericDomain($client['domain']) 
                ? $client['domain']  // For generic domains, domain field contains the full email
                : null;
            
            // If domain looks like an email address, use it directly
            if ($primaryEmail && strpos($primaryEmail, '@') !== false) {
                $contacts[] = [
                    'id' => 0,
                    'client_id' => $id,
                    'email' => $primaryEmail,
                    'name' => $client['display_name'] ?: $this->extractNameFromEmail($primaryEmail),
                    'last_email_at' => $client['last_activity_at'],
                    'email_count' => 1,
                    'is_primary' => true
                ];
            } else {
                // For company domains, create a placeholder indicating the domain
                $contacts[] = [
                    'id' => 0,
                    'client_id' => $id,
                    'email' => 'contact@' . $client['domain'],
                    'name' => $client['display_name'] ?: ucfirst(explode('.', $client['domain'])[0]),
                    'last_email_at' => $client['last_activity_at'],
                    'email_count' => 1,
                    'is_primary' => true,
                    'is_placeholder' => true
                ];
            }
        }
        
        $client['contacts'] = $contacts;
        $client['contact_count'] = count($contacts);
        
        // Include domain aliases (merged domains)
        try {
            $aliasStmt = $this->db->prepare('
                SELECT id, alias_domain, merged_from_name, created_at 
                FROM client_domain_aliases 
                WHERE user_email = ? AND client_id = ?
                ORDER BY created_at DESC
            ');
            $aliasStmt->execute([strtolower($userEmail), $id]);
            $client['domain_aliases'] = $aliasStmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            $client['domain_aliases'] = [];
        }
        
        return $client;
    }
    
    /**
     * Get client by domain
     */
    public function getClientByDomain(string $userEmail, string $domain): ?array
    {
        $userEmail = strtolower($userEmail);
        $domain = strtolower($domain);
        
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE user_email = ? AND domain = ?');
        $stmt->execute([$userEmail, $domain]);
        $client = $stmt->fetch();
        
        if ($client) {
            return $this->getClient($userEmail, $client['id']);
        }
        
        // Check aliases (domain may have been merged into another client)
        return $this->resolveAlias($userEmail, $domain);
    }
    
    /**
     * Find client by contact email address
     */
    public function findClientByEmail(string $userEmail, string $contactEmail): ?array
    {
        $userEmail = strtolower($userEmail);
        $contactEmail = strtolower($contactEmail);
        
        // First try to find by client contact
        $stmt = $this->db->prepare('
            SELECT c.* FROM clients c
            JOIN client_contacts cc ON cc.client_id = c.id
            WHERE c.user_email = ? AND cc.email = ?
            LIMIT 1
        ');
        $stmt->execute([$userEmail, $contactEmail]);
        $client = $stmt->fetch();
        
        if ($client) {
            return $this->getClient($userEmail, $client['id']);
        }
        
        // Try by domain (only for business domains, not generic email providers)
        $domain = substr($contactEmail, strpos($contactEmail, '@') + 1);
        if ($domain && !$this->isGenericDomain($domain)) {
            $client = $this->getClientByDomain($userEmail, $domain);
            if ($client) {
                return $client;
            }
        }
        
        // Try via domain aliases (merged clients)
        $isGeneric = $this->isGenericDomain($domain);
        $aliasKey = $isGeneric ? $contactEmail : $domain;
        $aliasClient = $this->resolveAlias($userEmail, $aliasKey);
        if ($aliasClient) {
            return $aliasClient;
        }
        
        // Try finding by display name containing email
        $stmt = $this->db->prepare('
            SELECT * FROM clients 
            WHERE user_email = ? AND (email = ? OR display_name LIKE ?)
            LIMIT 1
        ');
        $stmt->execute([$userEmail, $contactEmail, '%' . $contactEmail . '%']);
        $client = $stmt->fetch();
        
        if ($client) {
            return $this->getClient($userEmail, $client['id']);
        }
        
        return null;
    }
    
    /**
     * Update client display name
     */
    public function updateDisplayName(string $userEmail, int $id, string $displayName): ?array
    {
        $stmt = $this->db->prepare('
            UPDATE clients SET display_name = ? 
            WHERE user_email = ? AND id = ?
        ');
        $stmt->execute([$displayName, strtolower($userEmail), $id]);
        
        return $this->getClient($userEmail, $id);
    }
    
    /**
     * Link a Drive folder to a client
     */
    public function linkDriveFolder(string $userEmail, int $clientId, ?int $folderId): ?array
    {
        $stmt = $this->db->prepare('
            UPDATE clients SET drive_folder_id = ? 
            WHERE user_email = ? AND id = ?
        ');
        $stmt->execute([$folderId, strtolower($userEmail), $clientId]);
        
        return $this->getClient($userEmail, $clientId);
    }
    
    /**
     * Unlink Drive folder from a client
     */
    public function unlinkDriveFolder(string $userEmail, int $clientId): ?array
    {
        return $this->linkDriveFolder($userEmail, $clientId, null);
    }
    
    /**
     * Update client activity (called when emails are sent/received)
     */
    public function updateActivity(string $userEmail, string $contactEmail, string $direction = 'inbound', ?string $contactName = null): ?array
    {
        $client = $this->getOrCreateClient($userEmail, $contactEmail, $contactName);
        
        if (!$client) {
            return null;
        }
        
        // Update both general activity and direction-specific timestamp
        if ($direction === 'outbound') {
            $stmt = $this->db->prepare('
                UPDATE clients 
                SET last_activity_at = NOW(), 
                    last_email_direction = ?,
                    last_outbound_at = NOW()
                WHERE id = ?
            ');
        } else {
            $stmt = $this->db->prepare('
                UPDATE clients 
                SET last_activity_at = NOW(), 
                    last_email_direction = ?,
                    last_inbound_at = NOW()
                WHERE id = ?
            ');
        }
        $stmt->execute([$direction, $client['id']]);
        
        // Recalculate status
        $this->recalculateStatus($client['id']);
        
        return $this->getClient($userEmail, $client['id']);
    }
    
    /**
     * Recalculate client status based on activity and tasks
     * 
     * Status logic:
     * - active: Work progressing, emails within 7 days, no overdue tasks
     * - waiting: Last email was outgoing (waiting on client) OR waiting on internal work
     * - attention: Overdue task OR stalled communication (14+ days no response after outbound)
     */
    public function recalculateStatus(int $clientId): string
    {
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $client = $stmt->fetch();
        
        if (!$client) {
            return 'active';
        }
        
        $status = 'active';
        $now = new \DateTime();
        
        // Check for overdue tasks - immediate attention needed
        if ($client['overdue_task_count'] > 0) {
            $status = 'attention';
        }
        // Check last activity and direction
        elseif ($client['last_activity_at']) {
            $lastActivity = new \DateTime($client['last_activity_at']);
            $daysSinceActivity = $now->diff($lastActivity)->days;
            
            // If last email was outbound (we sent it) and no response in 14+ days
            if ($client['last_email_direction'] === 'outbound' && $daysSinceActivity >= 14) {
                $status = 'attention';
            }
            // If last email was outbound and within 14 days - waiting on client
            elseif ($client['last_email_direction'] === 'outbound' && $daysSinceActivity < 14) {
                $status = 'waiting';
            }
            // If we have open tasks and last email was inbound - waiting on internal work
            elseif ($client['open_task_count'] > 0 && $client['last_email_direction'] === 'inbound') {
                $status = 'waiting';
            }
        }
        
        // Update status in database
        $updateStmt = $this->db->prepare('UPDATE clients SET status = ? WHERE id = ?');
        $updateStmt->execute([$status, $clientId]);
        
        return $status;
    }
    
    /**
     * Update task counts for a client
     */
    public function updateTaskCounts(int $clientId, int $openTasks, int $overdueTasks, ?string $nextDeadline = null): void
    {
        $stmt = $this->db->prepare('
            UPDATE clients 
            SET open_task_count = ?, 
                overdue_task_count = ?,
                next_deadline = ?
            WHERE id = ?
        ');
        $stmt->execute([$openTasks, $overdueTasks, $nextDeadline, $clientId]);
        
        // Recalculate status after updating tasks
        $this->recalculateStatus($clientId);
    }
    
    /**
     * Get client snapshot (overview data)
     */
    public function getClientSnapshot(string $userEmail, int $clientId): ?array
    {
        $client = $this->getClient($userEmail, $clientId);
        
        if (!$client) {
            return null;
        }
        
        // Get linked boards
        $boardStmt = $this->db->prepare('
            SELECT cb.board_id, b.name as board_name, b.background_color
            FROM client_boards cb
            LEFT JOIN webmail_boards b ON b.id = cb.board_id
            WHERE cb.client_id = ?
        ');
        $boardStmt->execute([$clientId]);
        $boards = $boardStmt->fetchAll();
        
        // Get conversation threads involving this client
        $threads = $this->getClientThreads($userEmail, $client);
        
        // Get linked calendar events
        // For generic providers, domain field contains the full email (e.g. user@gmail.com)
        $clientEmailForSearch = $client['email'] ?? (strpos($client['domain'] ?? '', '@') !== false ? $client['domain'] : null);
        $calendarEvents = $this->getClientCalendarEvents($userEmail, $clientId, $clientEmailForSearch);
        
        // Get linked tasks from boards
        $tasks = $this->getClientTasks($userEmail, $clientId);
        
        // Calculate responsibility message
        $responsibility = $this->getResponsibilityMessage($client);
        
        return [
            'client' => $client,
            'open_work' => [
                'open_tasks' => $client['open_task_count'],
                'overdue_tasks' => $client['overdue_task_count'],
                'next_deadline' => $client['next_deadline'],
            ],
            'responsibility' => $responsibility,
            'linked_boards' => $boards,
            'threads' => $threads,
            'calendar_events' => $calendarEvents,
            'tasks' => $tasks,
        ];
    }
    
    /**
     * Get conversation threads for a client
     */
    private function getClientThreads(string $userEmail, array $client): array
    {
        $domain = $client['domain'] ?? null;
        
        if (!$domain) {
            return [];
        }
        
        // Build query to find conversations where client is sender or in participants
        $conditions = [];
        $params = [$userEmail];
        
        // If domain contains @, it's a full email (generic provider client like user@gmail.com)
        // Use exact email match
        if (strpos($domain, '@') !== false) {
            $conditions[] = 'cm.from_email = ?';
            $params[] = strtolower($domain);
        } elseif (!$this->isGenericDomain($domain)) {
            // Business domain (e.g., sanamoebel.com) - match all emails from this domain
            $conditions[] = 'cm.from_email LIKE ?';
            $params[] = '%@' . strtolower($domain);
        }
        // else: bare generic domain (gmail.com) without specific email - skip (shouldn't happen)
        
        // If no conditions were built, return empty
        if (empty($conditions)) {
            return [];
        }
        
        $whereClause = implode(' OR ', $conditions);
        
        // Get unique conversations. The conversation table no longer
        // carries a path; resolve it from any member's folder identity for
        // display.
        $convStmt = $this->db->prepare("
            SELECT DISTINCT c.conversation_id, c.subject, c.message_count, c.unread_count,
                   c.latest_date, c.latest_from, c.has_attachment, fi.current_path AS folder
            FROM webmail_conversations c
            INNER JOIN webmail_conversation_members cm ON c.user_email = cm.user_email AND c.conversation_id = cm.conversation_id
            LEFT JOIN webmail_folder_identity fi ON fi.id = c.folder_id
            WHERE c.user_email = ? AND ({$whereClause})
            ORDER BY c.latest_date DESC
            LIMIT 20
        ");
        $convStmt->execute($params);
        $conversations = $convStmt->fetchAll();

        $threads = [];
        foreach ($conversations as $conv) {
            $msgStmt = $this->db->prepare('
                SELECT cm.message_id, fi.current_path AS folder, cm.uid,
                       cm.subject, cm.from_email, cm.from_name, cm.message_date
                FROM webmail_conversation_members cm
                LEFT JOIN webmail_folder_identity fi ON fi.id = cm.folder_id
                WHERE cm.user_email = ? AND cm.conversation_id = ?
                ORDER BY cm.message_date ASC
            ');
            $msgStmt->execute([$userEmail, $conv['conversation_id']]);
            $messages = $msgStmt->fetchAll();
            
            $threads[] = [
                'conversation_id' => $conv['conversation_id'],
                'subject' => $conv['subject'],
                'message_count' => $conv['message_count'],
                'unread_count' => $conv['unread_count'],
                'last_date' => $conv['latest_date'],
                'latest_from' => $conv['latest_from'],
                'has_attachment' => $conv['has_attachment'],
                'folder' => $conv['folder'],
                'emails' => array_map(function($msg) {
                    return [
                        'message_id' => $msg['message_id'],
                        'folder' => $msg['folder'],
                        'uid' => $msg['uid'],
                        'subject' => $msg['subject'],
                        'from_email' => $msg['from_email'],
                        'from_name' => $msg['from_name'],
                        'date' => $msg['message_date'],
                    ];
                }, $messages),
            ];
        }
        
        return $threads;
    }
    
    /**
     * Get calendar events linked to a client
     */
    private function getClientCalendarEvents(string $userEmail, int $clientId, ?string $clientEmail): array
    {
        // First try by direct client link
        try {
            $stmt = $this->db->prepare('
                SELECT e.id, e.title, e.description, e.start_date, e.end_date, e.all_day,
                       e.calendar_id, c.name as calendar_name, c.color
                FROM webmail_calendar_events e
                LEFT JOIN webmail_calendars c ON e.calendar_id = c.id
                WHERE e.user_email = ? 
                AND (e.client_id = ? OR e.description LIKE ? OR e.title LIKE ?)
                AND e.start_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY e.start_date ASC
                LIMIT 10
            ');
            $emailPattern = $clientEmail ? "%{$clientEmail}%" : '%NOMATCH%';
            $stmt->execute([$userEmail, $clientId, $emailPattern, $emailPattern]);
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            error_log("getClientCalendarEvents error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get tasks linked to a client
     */
    private function getClientTasks(string $userEmail, int $clientId): array
    {
        try {
            // Get cards from linked boards
            $stmt = $this->db->prepare('
                SELECT bc.id, bc.title, bc.description, bc.due_date, bc.is_complete,
                       b.id as board_id, b.name as board_name, bl.name as list_name
                FROM webmail_board_cards bc
                INNER JOIN webmail_board_lists bl ON bc.list_id = bl.id
                INNER JOIN webmail_boards b ON bl.board_id = b.id
                INNER JOIN client_boards cb ON b.id = cb.board_id
                WHERE cb.client_id = ? AND bc.is_complete = 0
                ORDER BY bc.due_date ASC, bc.created_at DESC
                LIMIT 10
            ');
            $stmt->execute([$clientId]);
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            error_log("getClientTasks error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get comprehensive client data for full mind map overview
     */
    public function getClientFullOverview(string $userEmail, int $clientId): ?array
    {
        $client = $this->getClient($userEmail, $clientId);
        if (!$client) {
            return null;
        }
        
        // Get all linked boards with full details
        $boards = $this->getClientBoardsWithDetails($userEmail, $clientId);
        
        // Get drive folder info
        $driveInfo = $this->getClientDriveInfo($userEmail, $client);
        
        // Get all conversations with email thread details
        $threads = $this->getClientThreadsWithReplies($userEmail, $client);
        
        // Get calendar events
        $clientEmailForSearch = $client['email'] ?? (strpos($client['domain'] ?? '', '@') !== false ? $client['domain'] : null);
        $calendarEvents = $this->getClientCalendarEvents($userEmail, $clientId, $clientEmailForSearch);
        
        // Get emails linked to boards
        $emailBoardLinks = $this->getClientEmailBoardLinks($userEmail, $clientId);
        
        return [
            'client' => $client,
            'threads' => $threads,
            'boards' => $boards,
            'drive' => $driveInfo,
            'calendar_events' => $calendarEvents,
            'email_board_links' => $emailBoardLinks,
        ];
    }
    
    /**
     * Get client boards with cards, milestones, and financials
     */
    private function getClientBoardsWithDetails(string $userEmail, int $clientId): array
    {
        try {
            // Get linked boards
            $stmt = $this->db->prepare('
                SELECT b.id, b.name, b.description, b.background_color, b.payment_terms_days
                FROM client_boards cb
                INNER JOIN webmail_boards b ON cb.board_id = b.id
                WHERE cb.client_id = ?
            ');
            $stmt->execute([$clientId]);
            $boards = $stmt->fetchAll();
            
            foreach ($boards as &$board) {
                // Get lists with milestone info
                $listStmt = $this->db->prepare('
                    SELECT id, name, position, is_milestone, expected_amount, currency, invoice_date
                    FROM webmail_board_lists
                    WHERE board_id = ?
                    ORDER BY position
                ');
                $listStmt->execute([$board['id']]);
                $board['lists'] = $listStmt->fetchAll();
                
                // Get cards per list
                foreach ($board['lists'] as &$list) {
                    $cardStmt = $this->db->prepare('
                        SELECT id, title, description, due_date, is_complete, position
                        FROM webmail_board_cards
                        WHERE list_id = ?
                        ORDER BY position
                    ');
                    $cardStmt->execute([$list['id']]);
                    $list['cards'] = $cardStmt->fetchAll();
                    
                    // Calculate list progress
                    $totalCards = count($list['cards']);
                    $completedCards = count(array_filter($list['cards'], fn($c) => $c['is_complete']));
                    $list['progress'] = $totalCards > 0 ? round(($completedCards / $totalCards) * 100) : 0;
                    $list['total_cards'] = $totalCards;
                    $list['completed_cards'] = $completedCards;
                }
                
                // Calculate board totals
                $board['total_cards'] = array_sum(array_column($board['lists'], 'total_cards'));
                $board['completed_cards'] = array_sum(array_column($board['lists'], 'completed_cards'));
                $board['progress'] = $board['total_cards'] > 0 
                    ? round(($board['completed_cards'] / $board['total_cards']) * 100) 
                    : 0;
                
                // Get financial totals
                $board['financials'] = [
                    'total_expected' => 0,
                    'currencies' => [],
                ];
                foreach ($board['lists'] as $list) {
                    if (!empty($list['expected_amount']) && $list['expected_amount'] > 0) {
                        $currency = $list['currency'] ?? 'HUF';
                        if (!isset($board['financials']['currencies'][$currency])) {
                            $board['financials']['currencies'][$currency] = 0;
                        }
                        $board['financials']['currencies'][$currency] += (float)$list['expected_amount'];
                        $board['financials']['total_expected'] += (float)$list['expected_amount'];
                    }
                }
            }
            
            return $boards;
        } catch (\Exception $e) {
            error_log("getClientBoardsWithDetails error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get client drive folder info with file stats
     */
    private function getClientDriveInfo(string $userEmail, array $client): ?array
    {
        if (empty($client['drive_folder_id'])) {
            return null;
        }
        
        try {
            // Get folder info
            $stmt = $this->db->prepare('
                SELECT id, name, created_at
                FROM drive_folders
                WHERE id = ? AND user_email = ?
            ');
            $stmt->execute([$client['drive_folder_id'], $userEmail]);
            $folder = $stmt->fetch();
            
            if (!$folder) {
                return null;
            }
            
            // Get file stats recursively
            $stats = $this->getDriveFolderStats($userEmail, $client['drive_folder_id']);
            
            return [
                'folder_id' => $folder['id'],
                'folder_name' => $folder['name'],
                'total_files' => $stats['file_count'],
                'total_size' => $stats['total_size'],
                'total_size_formatted' => $this->formatFileSize($stats['total_size']),
                'subfolder_count' => $stats['folder_count'],
            ];
        } catch (\Exception $e) {
            error_log("getClientDriveInfo error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get drive folder stats recursively
     */
    private function getDriveFolderStats(string $userEmail, int $folderId): array
    {
        $fileCount = 0;
        $totalSize = 0;
        $folderCount = 0;
        
        try {
            // Get files in this folder
            $fileStmt = $this->db->prepare('
                SELECT COUNT(*) as count, COALESCE(SUM(file_size), 0) as size
                FROM drive_files
                WHERE folder_id = ? AND user_email = ?
            ');
            $fileStmt->execute([$folderId, $userEmail]);
            $fileStats = $fileStmt->fetch();
            $fileCount = (int)$fileStats['count'];
            $totalSize = (int)$fileStats['size'];
            
            // Get subfolders
            $folderStmt = $this->db->prepare('
                SELECT id FROM drive_folders
                WHERE parent_id = ? AND user_email = ?
            ');
            $folderStmt->execute([$folderId, $userEmail]);
            $subfolders = $folderStmt->fetchAll();
            $folderCount = count($subfolders);
            
            // Recurse into subfolders
            foreach ($subfolders as $subfolder) {
                $subStats = $this->getDriveFolderStats($userEmail, $subfolder['id']);
                $fileCount += $subStats['file_count'];
                $totalSize += $subStats['total_size'];
                $folderCount += $subStats['folder_count'];
            }
        } catch (\Exception $e) {
            error_log("getDriveFolderStats error: " . $e->getMessage());
        }
        
        return [
            'file_count' => $fileCount,
            'total_size' => $totalSize,
            'folder_count' => $folderCount,
        ];
    }
    
    /**
     * Format file size for display
     */
    private function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
    
    /**
     * Get threads with full reply chain info
     */
    private function getClientThreadsWithReplies(string $userEmail, array $client): array
    {
        $domain = $client['domain'] ?? null;
        
        if (!$domain) {
            return [];
        }
        
        try {
            // Build query conditions
            $conditions = [];
            $params = [$userEmail];
            
            // If domain contains @, it's a full email (generic provider client)
            if (strpos($domain, '@') !== false) {
                $conditions[] = 'cm.from_email = ?';
                $params[] = strtolower($domain);
            } elseif (!$this->isGenericDomain($domain)) {
                // Business domain - match all emails from this domain
                $conditions[] = 'cm.from_email LIKE ?';
                $params[] = '%@' . strtolower($domain);
            }
            
            if (empty($conditions)) {
                return [];
            }
            
            $whereClause = implode(' OR ', $conditions);
            
            $convStmt = $this->db->prepare("
                SELECT DISTINCT c.conversation_id, c.subject, c.message_count, c.unread_count,
                       c.latest_date, c.latest_from, c.has_attachment, fi.current_path AS folder
                FROM webmail_conversations c
                INNER JOIN webmail_conversation_members cm ON c.user_email = cm.user_email AND c.conversation_id = cm.conversation_id
                LEFT JOIN webmail_folder_identity fi ON fi.id = c.folder_id
                WHERE c.user_email = ? AND ({$whereClause})
                ORDER BY c.latest_date DESC
                LIMIT 30
            ");
            $convStmt->execute($params);
            $conversations = $convStmt->fetchAll();

            $threads = [];
            foreach ($conversations as $conv) {
                $msgStmt = $this->db->prepare('
                    SELECT cm.message_id, fi.current_path AS folder, cm.uid, cm.subject,
                           cm.from_email, cm.from_name, cm.message_date
                    FROM webmail_conversation_members cm
                    LEFT JOIN webmail_folder_identity fi ON fi.id = cm.folder_id
                    WHERE cm.user_email = ? AND cm.conversation_id = ?
                    ORDER BY cm.message_date ASC
                ');
                $msgStmt->execute([$userEmail, $conv['conversation_id']]);
                $messages = $msgStmt->fetchAll();
                
                // Build reply tree
                $emailNodes = [];
                foreach ($messages as $msg) {
                    $emailNodes[] = [
                        'message_id' => $msg['message_id'],
                        'folder' => $msg['folder'],
                        'uid' => $msg['uid'],
                        'subject' => $msg['subject'],
                        'from_email' => $msg['from_email'],
                        'from_name' => $msg['from_name'],
                        'date' => $msg['message_date'],
                        'is_from_client' => $this->isFromClient($msg['from_email'], $client),
                    ];
                }
                
                $threads[] = [
                    'conversation_id' => $conv['conversation_id'],
                    'subject' => $conv['subject'],
                    'message_count' => $conv['message_count'],
                    'unread_count' => $conv['unread_count'],
                    'last_date' => $conv['latest_date'],
                    'latest_from' => $conv['latest_from'],
                    'has_attachment' => $conv['has_attachment'],
                    'folder' => $conv['folder'],
                    'emails' => $emailNodes,
                ];
            }
            
            return $threads;
        } catch (\Exception $e) {
            error_log("getClientThreadsWithReplies error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if email is from client
     */
    private function isFromClient(string $email, array $client): bool
    {
        $email = strtolower(trim($email));
        $domain = $client['domain'] ?? null;
        
        if (!$domain) {
            return false;
        }
        
        // If domain is a full email (generic provider), match exact email
        if (strpos($domain, '@') !== false) {
            return $email === strtolower($domain);
        }
        
        // For business domains, match by domain part
        if (!$this->isGenericDomain($domain)) {
            return str_ends_with($email, '@' . strtolower($domain));
        }
        
        return false;
    }
    
    /**
     * Get email to board links for this client
     */
    private function getClientEmailBoardLinks(string $userEmail, int $clientId): array
    {
        try {
            $stmt = $this->db->prepare('
                SELECT be.id, be.board_id, be.email_uid as uid, be.email_folder as folder,
                       be.email_subject as subject, be.email_from as from_email,
                       be.thread_id as message_id, be.created_at,
                       b.name as board_name
                FROM webmail_board_emails be
                INNER JOIN webmail_boards b ON be.board_id = b.id
                INNER JOIN client_boards cb ON b.id = cb.board_id
                WHERE cb.client_id = ?
                ORDER BY be.created_at DESC
            ');
            $stmt->execute([$clientId]);
            return $stmt->fetchAll();
        } catch (\Exception $e) {
            error_log("getClientEmailBoardLinks error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get responsibility message based on client state
     */
    private function getResponsibilityMessage(array $client): string
    {
        if ($client['overdue_task_count'] > 0) {
            return 'Overdue tasks require attention';
        }
        
        if ($client['last_email_direction'] === 'outbound') {
            return 'Waiting on client response';
        }
        
        if ($client['open_task_count'] > 0) {
            return 'Waiting on internal work';
        }
        
        if (!$client['last_activity_at']) {
            return 'No recent activity';
        }
        
        return 'Work progressing';
    }
    
    /**
     * Link a board to a client
     * Also auto-links the board's drive folder to the client if client has no folder
     */
    public function linkBoard(int $clientId, int $boardId, ?string $userEmail = null, ?array $config = null): bool
    {
        try {
            $stmt = $this->db->prepare('
                INSERT IGNORE INTO client_boards (client_id, board_id)
                VALUES (?, ?)
            ');
            $stmt->execute([$clientId, $boardId]);
            
            // Auto-link board's drive folder to client if client doesn't have one
            if ($userEmail && $config) {
                $this->autoLinkBoardFolderToClient($clientId, $boardId, $userEmail, $config);
            }
            
            return true;
        } catch (\PDOException $e) {
            error_log("ClientService linkBoard error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Auto-link a board's drive folder to a client
     * Only links if client doesn't already have a drive folder
     */
    private function autoLinkBoardFolderToClient(int $clientId, int $boardId, string $userEmail, array $config): void
    {
        try {
            // Check if client already has a drive folder
            $stmt = $this->db->prepare('SELECT drive_folder_id FROM clients WHERE id = ?');
            $stmt->execute([$clientId]);
            $client = $stmt->fetch();
            
            if (!$client || !empty($client['drive_folder_id'])) {
                // Client not found or already has a folder
                return;
            }
            
            // Get board's drive folder
            $boardService = new \Webmail\Addons\KanbanBoards\Services\BoardService($config);
            $boardFolder = $boardService->getBoardDriveFolder($boardId);
            
            if ($boardFolder && !empty($boardFolder['id'])) {
                // Link the folder to the client
                $stmt = $this->db->prepare('UPDATE clients SET drive_folder_id = ? WHERE id = ?');
                $stmt->execute([$boardFolder['id'], $clientId]);
                error_log("ClientService: Auto-linked board folder #{$boardFolder['id']} ({$boardFolder['name']}) to client #$clientId");
            }
        } catch (\Exception $e) {
            error_log("ClientService autoLinkBoardFolderToClient error: " . $e->getMessage());
        }
    }
    
    /**
     * Sync drive folder from linked boards to client
     * Used to fix existing clients that don't have drive folders linked
     */
    public function syncDriveFolderFromBoards(string $userEmail, int $clientId, array $config): ?array
    {
        try {
            // Check if client already has a drive folder
            $stmt = $this->db->prepare('SELECT id, drive_folder_id FROM clients WHERE id = ? AND user_email = ?');
            $stmt->execute([$clientId, strtolower($userEmail)]);
            $client = $stmt->fetch();
            
            if (!$client) {
                return ['success' => false, 'error' => 'Client not found'];
            }
            
            if (!empty($client['drive_folder_id'])) {
                return ['success' => true, 'message' => 'Client already has a drive folder', 'folder_id' => $client['drive_folder_id']];
            }
            
            // Get linked board IDs
            $boardIds = $this->getLinkedBoardIds($clientId);
            if (empty($boardIds)) {
                return ['success' => false, 'error' => 'No boards linked to this client'];
            }
            
            // Try to find a folder from any linked board
            $boardService = new \Webmail\Addons\KanbanBoards\Services\BoardService($config);
            foreach ($boardIds as $boardId) {
                $boardFolder = $boardService->getBoardDriveFolder($boardId);
                if ($boardFolder && !empty($boardFolder['id'])) {
                    // Link the folder to the client
                    $stmt = $this->db->prepare('UPDATE clients SET drive_folder_id = ? WHERE id = ?');
                    $stmt->execute([$boardFolder['id'], $clientId]);
                    
                    return [
                        'success' => true,
                        'message' => "Linked folder '{$boardFolder['name']}' from board #$boardId",
                        'folder_id' => $boardFolder['id'],
                        'folder_name' => $boardFolder['name']
                    ];
                }
            }
            
            return ['success' => false, 'error' => 'No linked boards have drive folders'];
        } catch (\Exception $e) {
            error_log("ClientService syncDriveFolderFromBoards error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Unlink a board from a client
     */
    public function unlinkBoard(int $clientId, int $boardId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM client_boards WHERE client_id = ? AND board_id = ?');
        $stmt->execute([$clientId, $boardId]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get all linked board IDs for a client
     */
    public function getLinkedBoardIds(int $clientId): array
    {
        $stmt = $this->db->prepare('SELECT board_id FROM client_boards WHERE client_id = ?');
        $stmt->execute([$clientId]);
        return array_column($stmt->fetchAll(), 'board_id');
    }
    
    /**
     * Get all board-to-client mappings for a user
     * Returns array of [board_id => client_id, client_name]
     */
    public function getAllBoardMappings(string $userEmail): array
    {
        $userEmail = strtolower($userEmail);
        
        $stmt = $this->db->prepare('
            SELECT cb.board_id, cb.client_id, c.domain, c.display_name
            FROM client_boards cb
            JOIN clients c ON c.id = cb.client_id
            WHERE c.user_email = ?
        ');
        $stmt->execute([$userEmail]);
        
        $mapping = [];
        foreach ($stmt->fetchAll() as $row) {
            $mapping[(string)$row['board_id']] = [
                'client_id' => (int)$row['client_id'],
                'client_name' => $row['display_name'] ?? $row['domain']
            ];
        }
        
        return $mapping;
    }
    
    /**
     * GET /clients/mood-board-mapping - Get mood_board_id to client_id mapping
     * Used for time tracking mood board activities
     */
    public function getAllMoodBoardMappings(string $userEmail): array
    {
        $userEmail = strtolower($userEmail);
        
        // Get mood boards linked to clients via mood_board_client_links table
        $stmt = $this->db->prepare('
            SELECT mcl.mood_board_id, mcl.client_id, c.domain, c.display_name
            FROM mood_board_client_links mcl
            JOIN clients c ON c.id = mcl.client_id
            WHERE c.user_email = ?
        ');
        $stmt->execute([$userEmail]);
        
        $mapping = [];
        foreach ($stmt->fetchAll() as $row) {
            $mapping[(string)$row['mood_board_id']] = [
                'client_id' => (int)$row['client_id'],
                'client_name' => $row['display_name'] ?? $row['domain']
            ];
        }
        
        // Also include mood boards that have client_id set directly
        $stmt2 = $this->db->prepare('
            SELECT mb.id as mood_board_id, mb.client_id, c.domain, c.display_name
            FROM mood_boards mb
            JOIN clients c ON c.id = mb.client_id
            WHERE mb.owner_email = ? AND mb.client_id IS NOT NULL
        ');
        $stmt2->execute([$userEmail]);
        
        foreach ($stmt2->fetchAll() as $row) {
            // Don't overwrite link-table entries
            if (!isset($mapping[(string)$row['mood_board_id']])) {
                $mapping[(string)$row['mood_board_id']] = [
                    'client_id' => (int)$row['client_id'],
                    'client_name' => $row['display_name'] ?? $row['domain']
                ];
            }
        }
        
        return $mapping;
    }
    
    /**
     * Sync clients from recent emails (batch processing)
     * This extracts clients from email senders/recipients
     */
    public function syncFromEmails(string $userEmail, array $emails): int
    {
        $created = 0;
        $userEmail = strtolower($userEmail);
        
        foreach ($emails as $email) {
            // Process sender
            if (!empty($email['from_email']) || !empty($email['from']['address'])) {
                $fromEmail = $email['from_email'] ?? $email['from']['address'] ?? null;
                $fromName = $email['from_name'] ?? $email['from']['name'] ?? null;
                
                if ($fromEmail && strtolower($fromEmail) !== $userEmail) {
                    $client = $this->getOrCreateClient($userEmail, $fromEmail, $fromName);
                    if ($client) {
                        $created++;
                        // Update with inbound direction
                        $this->updateActivityDirect($client['id'], 'inbound', $email['date'] ?? null);
                    }
                }
            }
            
            // Process recipients (To, Cc)
            $recipients = [];
            if (!empty($email['to'])) {
                $recipients = array_merge($recipients, is_array($email['to']) ? $email['to'] : [$email['to']]);
            }
            if (!empty($email['cc'])) {
                $recipients = array_merge($recipients, is_array($email['cc']) ? $email['cc'] : [$email['cc']]);
            }
            
            foreach ($recipients as $recipient) {
                $recipientEmail = is_array($recipient) ? ($recipient['address'] ?? null) : $recipient;
                $recipientName = is_array($recipient) ? ($recipient['name'] ?? null) : null;
                
                if ($recipientEmail && strtolower($recipientEmail) !== $userEmail) {
                    $client = $this->getOrCreateClient($userEmail, $recipientEmail, $recipientName);
                    if ($client) {
                        $created++;
                    }
                }
            }
        }
        
        return $created;
    }
    
    /**
     * Update activity directly with timestamp
     */
    private function updateActivityDirect(int $clientId, string $direction, ?string $timestamp = null): void
    {
        $datetime = $timestamp ? date('Y-m-d H:i:s', strtotime($timestamp)) : date('Y-m-d H:i:s');
        
        // Only update if this is more recent, and update the appropriate direction timestamp
        if ($direction === 'outbound') {
            $stmt = $this->db->prepare('
                UPDATE clients 
                SET last_activity_at = GREATEST(COALESCE(last_activity_at, ?), ?),
                    last_email_direction = CASE 
                        WHEN last_activity_at IS NULL OR last_activity_at <= ? THEN ?
                        ELSE last_email_direction
                    END,
                    last_outbound_at = GREATEST(COALESCE(last_outbound_at, ?), ?)
                WHERE id = ?
            ');
            $stmt->execute([$datetime, $datetime, $datetime, $direction, $datetime, $datetime, $clientId]);
        } else {
            $stmt = $this->db->prepare('
                UPDATE clients 
                SET last_activity_at = GREATEST(COALESCE(last_activity_at, ?), ?),
                    last_email_direction = CASE 
                        WHEN last_activity_at IS NULL OR last_activity_at <= ? THEN ?
                        ELSE last_email_direction
                    END,
                    last_inbound_at = GREATEST(COALESCE(last_inbound_at, ?), ?)
                WHERE id = ?
            ');
            $stmt->execute([$datetime, $datetime, $datetime, $direction, $datetime, $datetime, $clientId]);
        }
    }
    
    /**
     * Get client counts by status
     */
    public function getStatusCounts(string $userEmail): array
    {
        $stmt = $this->db->prepare('
            SELECT status, COUNT(*) as count 
            FROM clients 
            WHERE user_email = ? 
            GROUP BY status
        ');
        $stmt->execute([strtolower($userEmail)]);
        
        $counts = [
            'attention' => 0,
            'waiting' => 0,
            'active' => 0,
            'total' => 0
        ];
        
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['status']] = (int)$row['count'];
            $counts['total'] += (int)$row['count'];
        }
        
        return $counts;
    }
    
    /**
     * Delete a client
     */
    public function deleteClient(string $userEmail, int $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM clients WHERE user_email = ? AND id = ?');
        $stmt->execute([strtolower($userEmail), $id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Recalculate all client statuses (maintenance task)
     */
    public function recalculateAllStatuses(string $userEmail): int
    {
        $stmt = $this->db->prepare('SELECT id FROM clients WHERE user_email = ?');
        $stmt->execute([strtolower($userEmail)]);
        
        $updated = 0;
        foreach ($stmt->fetchAll() as $row) {
            $this->recalculateStatus($row['id']);
            $updated++;
        }
        
        return $updated;
    }
    
    /**
     * Get empty email stats structure (used when IMAP is unavailable)
     */
    public function getEmptyEmailStats(): array
    {
        return [
            'total_emails' => 0,
            'sent_emails' => 0,
            'received_emails' => 0,
            'conversations' => 0,
            'active_threads' => 0,
            'their_avg_response_hours' => null,
            'your_avg_response_hours' => null,
            'recent_emails' => []
        ];
    }
    
    /**
     * Get email statistics for a client domain (deprecated - use getEmailStatsWithImap)
     */
    public function getEmailStats(string $userEmail, string $clientDomain): array
    {
        return $this->getEmptyEmailStats();
    }
    
    /**
     * Get email statistics for a client domain using an existing IMAP connection
     * Searches ALL folders for accurate counts
     */
    public function getEmailStatsWithImap(ImapService $imapService, string $userEmail, string $clientDomain): array
    {
        $stats = [
            'total_emails' => 0,
            'sent_emails' => 0,
            'received_emails' => 0,
            'conversations' => 0,
            'active_threads' => 0,
            'their_avg_response_hours' => null,
            'your_avg_response_hours' => null,
            'recent_emails' => [],
            'discovered_contacts' => [],
            'last_outbound_at' => null,
            'last_inbound_at' => null
        ];
        
        $contactsMap = [];
        $lastOutbound = null;
        $lastInbound = null;
        $threadIds = [];
        $recentEmails = [];
        $userEmailLower = strtolower($userEmail);
        
        // Folders to skip
        $skipFolders = ['trash', 'spam', 'junk', 'drafts', '[gmail]/trash', '[gmail]/spam', '[gmail]/drafts'];
        
        try {
            $isFullEmail = strpos($clientDomain, '@') !== false;
            
            // Safety check: never search by bare generic domain (gmail.com, yahoo.com, etc.)
            // This would match ALL emails from that provider
            if (!$isFullEmail && $this->isGenericDomain($clientDomain)) {
                error_log("ClientService: Refusing to search by generic domain '$clientDomain' - would match all emails from this provider");
                return $stats;
            }
            
            $searchDomain = $isFullEmail ? $clientDomain : '@' . $clientDomain;
            
            error_log("ClientService: Searching ALL folders for emails with domain: $searchDomain");
            
            // Get ALL folders
            $folders = $imapService->listFolders();
            error_log("ClientService: Found " . count($folders) . " folders to search");
            
            foreach ($folders as $folder) {
                $folderName = $folder['name'] ?? $folder;
                $folderLower = strtolower($folderName);
                
                // Skip trash, spam, junk, drafts
                $shouldSkip = false;
                foreach ($skipFolders as $skip) {
                    if (strpos($folderLower, $skip) !== false) {
                        $shouldSkip = true;
                        break;
                    }
                }
                if ($shouldSkip) {
                    continue;
                }
                
                try {
                    if (!$imapService->selectFolder($folderName)) {
                        continue;
                    }
                    
                    // Search for emails FROM this domain (received)
                    $fromUids = $imapService->searchMessages('FROM "' . addslashes($searchDomain) . '"');
                    if ($fromUids === false) $fromUids = [];
                    
                    // Search for emails TO this domain (sent)
                    $toUids = $imapService->searchMessages('TO "' . addslashes($searchDomain) . '"');
                    if ($toUids === false) $toUids = [];
                    
                    // Also search CC field
                    $ccUids = $imapService->searchMessages('CC "' . addslashes($searchDomain) . '"');
                    if ($ccUids === false) $ccUids = [];
                    
                    // Merge TO and CC UIDs
                    $toUids = array_unique(array_merge($toUids, $ccUids));
                    
                    $folderReceivedCount = count($fromUids);
                    $folderSentCount = count($toUids);
                    
                    if ($folderReceivedCount > 0 || $folderSentCount > 0) {
                        error_log("ClientService: Folder '$folderName': $folderReceivedCount received, $folderSentCount sent");
                    }
                    
                    $stats['received_emails'] += $folderReceivedCount;
                    $stats['sent_emails'] += $folderSentCount;
                    
                    // Process received emails (FROM client)
                    if (count($fromUids) > 0) {
                        $recentFromUids = array_slice($fromUids, -10);
                        foreach ($recentFromUids as $uid) {
                            try {
                                $msg = $imapService->getMessage($folderName, $uid);
                                if (!$msg) continue;
                                
                                $subject = preg_replace('/^(Re:|Fwd?:)\s*/i', '', $msg['subject'] ?? '');
                                $threadIds[$subject] = true;
                                
                                // from is an array: [['email' => 'x@y.com', 'name' => 'Name', 'display' => '...']]
                                $fromData = $msg['from'][0] ?? [];
                                $fromEmail = strtolower($fromData['email'] ?? '');
                                $fromName = $fromData['name'] ?? '';
                                $fromDisplay = $fromData['display'] ?? $fromEmail;
                                $emailDate = $msg['date'] ?? null;
                                
                                // Debug logging
                                error_log("ClientService: Processing received email UID=$uid, fromEmail='$fromEmail', fromName='$fromName', subject='{$msg['subject']}'");
                                
                                // IMAP search found this by domain, so the FROM must be from client
                                // Add to contacts map using the email we found
                                if ($fromEmail) {
                                    if (!isset($contactsMap[$fromEmail])) {
                                        $contactsMap[$fromEmail] = [
                                            'email' => $fromEmail,
                                            'name' => $fromName,
                                            'email_count' => 0,
                                            'last_email_at' => null
                                        ];
                                    }
                                    $contactsMap[$fromEmail]['email_count']++;
                                    if (!$contactsMap[$fromEmail]['last_email_at'] || 
                                        strtotime($emailDate) > strtotime($contactsMap[$fromEmail]['last_email_at'])) {
                                        $contactsMap[$fromEmail]['last_email_at'] = $emailDate;
                                    }
                                    if ($fromName && !$contactsMap[$fromEmail]['name']) {
                                        $contactsMap[$fromEmail]['name'] = $fromName;
                                    }
                                }
                                
                                if ($emailDate && (!$lastInbound || strtotime($emailDate) > strtotime($lastInbound))) {
                                    $lastInbound = $emailDate;
                                }
                                
                                // Always add to recent emails since IMAP found it - use display as fallback
                                $contactDisplay = $fromEmail ?: $fromDisplay ?: $fromName ?: 'Unknown';
                                $recentEmails[] = [
                                    'uid' => $uid,
                                    'folder' => $folderName,
                                    'subject' => $msg['subject'],
                                    'contact' => $contactDisplay,
                                    'contact_name' => $fromName,
                                    'date' => $emailDate,
                                    'direction' => 'received'
                                ];
                            } catch (\Exception $e) {
                                continue;
                            }
                        }
                    }
                    
                    // Process sent emails (TO client)
                    if (count($toUids) > 0) {
                        $recentToUids = array_slice($toUids, -10);
                        foreach ($recentToUids as $uid) {
                            try {
                                $msg = $imapService->getMessage($folderName, $uid);
                                if (!$msg) continue;
                                
                                $subject = preg_replace('/^(Re:|Fwd?:)\s*/i', '', $msg['subject'] ?? '');
                                $threadIds[$subject] = true;
                                
                                // Extract contacts from To and CC fields (arrays of ['email' => ..., 'name' => ...])
                                $allRecipients = [];
                                if (!empty($msg['to']) && is_array($msg['to'])) {
                                    $allRecipients = array_merge($allRecipients, $msg['to']);
                                }
                                if (!empty($msg['cc']) && is_array($msg['cc'])) {
                                    $allRecipients = array_merge($allRecipients, $msg['cc']);
                                }
                                
                                $emailDate = $msg['date'] ?? null;
                                
                                foreach ($allRecipients as $recipient) {
                                    // recipient is ['email' => 'x@y.com', 'name' => 'Name']
                                    $recipientEmail = strtolower($recipient['email'] ?? '');
                                    $recipientName = $recipient['name'] ?? '';
                                    
                                    if ($recipientEmail && $this->emailMatchesDomain($recipientEmail, $clientDomain)) {
                                        if (!isset($contactsMap[$recipientEmail])) {
                                            $contactsMap[$recipientEmail] = [
                                                'email' => $recipientEmail,
                                                'name' => $recipientName,
                                                'email_count' => 0,
                                                'last_email_at' => null
                                            ];
                                        }
                                        $contactsMap[$recipientEmail]['email_count']++;
                                        if (!$contactsMap[$recipientEmail]['last_email_at'] || 
                                            strtotime($emailDate) > strtotime($contactsMap[$recipientEmail]['last_email_at'])) {
                                            $contactsMap[$recipientEmail]['last_email_at'] = $emailDate;
                                        }
                                        if ($recipientName && !$contactsMap[$recipientEmail]['name']) {
                                            $contactsMap[$recipientEmail]['name'] = $recipientName;
                                        }
                                    }
                                }
                                
                                // Check if this is actually a sent email (from user)
                                $msgFromData = $msg['from'][0] ?? [];
                                $msgFrom = strtolower($msgFromData['email'] ?? '');
                                $isSent = ($msgFrom === $userEmailLower || strpos($msgFrom, '@' . explode('@', $userEmailLower)[1]) !== false);
                                
                                if ($isSent && $emailDate && (!$lastOutbound || strtotime($emailDate) > strtotime($lastOutbound))) {
                                    $lastOutbound = $emailDate;
                                }
                                
                                // Get first recipient for recent emails list
                                $toContact = '';
                                $toName = '';
                                foreach ($allRecipients as $recipient) {
                                    $recipientEmail = strtolower($recipient['email'] ?? '');
                                    if ($recipientEmail && $this->emailMatchesDomain($recipientEmail, $clientDomain)) {
                                        $toContact = $recipientEmail;
                                        $toName = $recipient['name'] ?? '';
                                        break;
                                    }
                                }
                                
                                if ($toContact) {
                                    $recentEmails[] = [
                                        'uid' => $uid,
                                        'folder' => $folderName,
                                        'subject' => $msg['subject'],
                                        'contact' => $toContact,
                                        'contact_name' => $toName,
                                        'date' => $emailDate,
                                        'direction' => $isSent ? 'sent' : 'received'
                                    ];
                                }
                            } catch (\Exception $e) {
                                continue;
                            }
                        }
                    }
                    
                } catch (\Exception $e) {
                    error_log("ClientService: Error searching folder '$folderName': " . $e->getMessage());
                    continue;
                }
            }
            
            $stats['total_emails'] = $stats['received_emails'] + $stats['sent_emails'];
            
            // Sort recent emails by date desc and take top 5
            usort($recentEmails, function($a, $b) {
                return strtotime($b['date'] ?? '0') - strtotime($a['date'] ?? '0');
            });
            $stats['recent_emails'] = array_slice($recentEmails, 0, 5);
            
            $stats['conversations'] = count($threadIds);
            
            // Active threads (activity in last 30 days)
            $thirtyDaysAgo = strtotime('-30 days');
            $activeCount = 0;
            foreach ($recentEmails as $email) {
                if (strtotime($email['date'] ?? '0') > $thirtyDaysAgo) {
                    $activeCount++;
                }
            }
            $stats['active_threads'] = min($activeCount, $stats['conversations']);
            
            // Convert contacts map to array and sort by email count
            $discoveredContacts = array_values($contactsMap);
            usort($discoveredContacts, function($a, $b) {
                return $b['email_count'] - $a['email_count'];
            });
            $stats['discovered_contacts'] = $discoveredContacts;
            
            $stats['last_outbound_at'] = $lastOutbound;
            $stats['last_inbound_at'] = $lastInbound;
            
            error_log("ClientService: Total stats - received:{$stats['received_emails']}, sent:{$stats['sent_emails']}, conversations:{$stats['conversations']}, contacts:" . count($discoveredContacts));
            
        } catch (\Exception $e) {
            error_log("ClientService getEmailStatsWithImap error: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Check if an email address matches a client domain
     * For full email domains (like Gmail), matches exact email
     * For company domains, matches the domain part
     */
    private function emailMatchesDomain(string $email, string $clientDomain): bool
    {
        $email = strtolower(trim($email));
        $clientDomain = strtolower(trim($clientDomain));
        
        // If client domain is a full email (generic provider), match exact email
        if (strpos($clientDomain, '@') !== false) {
            return $email === $clientDomain;
        }
        
        // Never match by domain for generic email providers (gmail, yahoo, etc.)
        // A client with domain 'gmail.com' should not match ALL gmail users
        if ($this->isGenericDomain($clientDomain)) {
            return false;
        }
        
        // For business domains, match by domain part
        $emailDomain = $this->extractDomain($email);
        return $emailDomain === $clientDomain;
    }
    
    // =========================================================================
    // CLIENT INFO MANAGEMENT
    // =========================================================================
    
    /**
     * Update client information (phone, address, notes, payment terms)
     */
    public function updateClientInfo(string $userEmail, int $id, array $data): ?array
    {
        // Get current client for comparison
        $currentClient = $this->getClient($userEmail, $id);
        
        $allowedFields = [
            'display_name', 'phone', 'address', 'notes', 'payment_terms_days', 'hourly_rate',
            'billing_name', 'billing_tax_id', 'billing_eu_tax_id',
            'billing_address', 'billing_city', 'billing_zip', 'billing_country', 'billing_bank_account',
        ];
        $updates = [];
        $params = [];
        $changes = [];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
                
                // Track changes for activity log
                if ($currentClient && ($currentClient[$field] ?? null) !== $data[$field]) {
                    $changes[$field] = ['from' => $currentClient[$field] ?? null, 'to' => $data[$field]];
                }
            }
        }
        
        if (empty($updates)) {
            return $this->getClient($userEmail, $id);
        }
        
        $params[] = strtolower($userEmail);
        $params[] = $id;
        
        $sql = 'UPDATE clients SET ' . implode(', ', $updates) . ' WHERE user_email = ? AND id = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        // Log activity if there were changes
        if (!empty($changes)) {
            $this->logActivity($userEmail, 'client_updated', 'client', $id, 
                $currentClient['display_name'] ?? 'Client', null, $id, [
                    'changes' => $changes
                ]);
        }
        
        return $this->getClient($userEmail, $id);
    }
    
    // =========================================================================
    // ASSOCIATED ACCOUNTS
    // =========================================================================
    
    /**
     * Get or create an associated account (for CC recipients)
     * Associated accounts are linked to a primary client but appear as secondary contacts
     */
    public function getOrCreateAssociatedAccount(string $userEmail, string $contactEmail, int $primaryClientId, ?string $contactName = null): ?array
    {
        $userEmail = strtolower($userEmail);
        $contactEmail = strtolower(trim($contactEmail));
        $domain = $this->extractDomain($contactEmail);
        
        if (!$domain) {
            return null;
        }
        
        // For generic domains, use full email as identifier
        $isGeneric = $this->isGenericDomain($domain);
        $clientIdentifier = $isGeneric ? $contactEmail : $domain;
        
        // Check if this would be the same as the primary client
        $primaryClient = $this->getClient($userEmail, $primaryClientId);
        if ($primaryClient && $primaryClient['domain'] === $clientIdentifier) {
            // Same domain as primary - just add as contact, don't create associated account
            $this->addContact($primaryClientId, $contactEmail, $contactName);
            return null;
        }
        
        // Check if client/associated account already exists
        $stmt = $this->db->prepare('SELECT * FROM clients WHERE user_email = ? AND domain = ?');
        $stmt->execute([$userEmail, $clientIdentifier]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Already exists - add contact and return
            $this->addContact($existing['id'], $contactEmail, $contactName);
            return $existing;
        }
        
        // Create new associated account
        $displayName = $isGeneric 
            ? ($contactName ?: $this->extractNameFromEmail($contactEmail))
            : $this->generateDisplayName($domain);
        
        $stmt = $this->db->prepare('
            INSERT INTO clients (user_email, domain, display_name, is_associated, associated_with_client_id, last_activity_at)
            VALUES (?, ?, ?, 1, ?, NOW())
        ');
        $stmt->execute([$userEmail, $clientIdentifier, $displayName, $primaryClientId]);
        
        $clientId = (int)$this->db->lastInsertId();
        $this->addContact($clientId, $contactEmail, $contactName);
        
        return $this->getClient($userEmail, $clientId);
    }
    
    /**
     * Get all associated accounts for a client
     */
    public function getAssociatedAccounts(string $userEmail, int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM clients 
            WHERE user_email = ? AND associated_with_client_id = ?
            ORDER BY display_name ASC
        ');
        $stmt->execute([strtolower($userEmail), $clientId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Promote an associated account to a full client
     */
    public function promoteToClient(string $userEmail, int $associatedId): ?array
    {
        $stmt = $this->db->prepare('
            UPDATE clients 
            SET is_associated = 0, associated_with_client_id = NULL 
            WHERE user_email = ? AND id = ?
        ');
        $stmt->execute([strtolower($userEmail), $associatedId]);
        
        return $this->getClient($userEmail, $associatedId);
    }
    
    /**
     * Mark an existing client as associated with another client
     */
    public function markAsAssociated(string $userEmail, int $clientId, int $primaryClientId): ?array
    {
        $stmt = $this->db->prepare('
            UPDATE clients 
            SET is_associated = 1, associated_with_client_id = ? 
            WHERE user_email = ? AND id = ?
        ');
        $stmt->execute([$primaryClientId, strtolower($userEmail), $clientId]);
        
        return $this->getClient($userEmail, $clientId);
    }
    
    // =========================================================================
    // CLIENT MERGE
    // =========================================================================
    
    /**
     * Merge two clients - all data from secondary is moved to primary
     * @param string $userEmail User email
     * @param int $primaryId The client to keep
     * @param int $secondaryId The client to merge and delete
     */
    public function mergeClients(string $userEmail, int $primaryId, int $secondaryId): ?array
    {
        $userEmail = strtolower($userEmail);
        
        // Verify both clients exist and belong to user
        $primary = $this->getClient($userEmail, $primaryId);
        $secondary = $this->getClient($userEmail, $secondaryId);
        
        if (!$primary || !$secondary) {
            return null;
        }
        
        $this->db->beginTransaction();
        
        try {
            // 1. Move all contacts from secondary to primary
            $stmt = $this->db->prepare('
                UPDATE IGNORE client_contacts 
                SET client_id = ? 
                WHERE client_id = ?
            ');
            $stmt->execute([$primaryId, $secondaryId]);
            
            // Delete any duplicate contacts that couldn't be moved
            $stmt = $this->db->prepare('DELETE FROM client_contacts WHERE client_id = ?');
            $stmt->execute([$secondaryId]);
            
            // 2. Move all board links from secondary to primary
            $stmt = $this->db->prepare('
                UPDATE IGNORE client_boards 
                SET client_id = ? 
                WHERE client_id = ?
            ');
            $stmt->execute([$primaryId, $secondaryId]);
            
            // Delete any duplicate board links
            $stmt = $this->db->prepare('DELETE FROM client_boards WHERE client_id = ?');
            $stmt->execute([$secondaryId]);
            
            // 3. Update any associated accounts pointing to secondary
            $stmt = $this->db->prepare('
                UPDATE clients 
                SET associated_with_client_id = ? 
                WHERE associated_with_client_id = ?
            ');
            $stmt->execute([$primaryId, $secondaryId]);
            
            // 4. Merge activity data - take the most recent
            $stmt = $this->db->prepare('
                UPDATE clients 
                SET 
                    last_activity_at = GREATEST(COALESCE(last_activity_at, ?), COALESCE(?, last_activity_at)),
                    last_outbound_at = GREATEST(COALESCE(last_outbound_at, ?), COALESCE(?, last_outbound_at)),
                    last_inbound_at = GREATEST(COALESCE(last_inbound_at, ?), COALESCE(?, last_inbound_at)),
                    open_task_count = open_task_count + ?,
                    overdue_task_count = overdue_task_count + ?
                WHERE id = ?
            ');
            $stmt->execute([
                $secondary['last_activity_at'], $secondary['last_activity_at'],
                $secondary['last_outbound_at'], $secondary['last_outbound_at'],
                $secondary['last_inbound_at'], $secondary['last_inbound_at'],
                $secondary['open_task_count'] ?? 0,
                $secondary['overdue_task_count'] ?? 0,
                $primaryId
            ]);
            
            // 5. Merge notes if secondary has notes
            if (!empty($secondary['notes'])) {
                $mergedNotes = trim(($primary['notes'] ?? '') . "\n\n[Merged from {$secondary['display_name']}]\n" . $secondary['notes']);
                $stmt = $this->db->prepare('UPDATE clients SET notes = ? WHERE id = ?');
                $stmt->execute([$mergedNotes, $primaryId]);
            }
            
            // 6. Transfer drive folder if primary doesn't have one
            if (empty($primary['drive_folder_id']) && !empty($secondary['drive_folder_id'])) {
                $stmt = $this->db->prepare('UPDATE clients SET drive_folder_id = ? WHERE id = ?');
                $stmt->execute([$secondary['drive_folder_id'], $primaryId]);
            }
            
            // 7. Transfer phone if primary doesn't have one
            if (empty($primary['phone']) && !empty($secondary['phone'])) {
                $stmt = $this->db->prepare('UPDATE clients SET phone = ? WHERE id = ?');
                $stmt->execute([$secondary['phone'], $primaryId]);
            }
            
            // 8. Transfer address if primary doesn't have one
            if (empty($primary['address']) && !empty($secondary['address'])) {
                $stmt = $this->db->prepare('UPDATE clients SET address = ? WHERE id = ?');
                $stmt->execute([$secondary['address'], $primaryId]);
            }
            
            // 9. Transfer payment terms if primary uses default but secondary has custom
            if (($primary['payment_terms_days'] ?? 30) == 30 && 
                !empty($secondary['payment_terms_days']) && 
                $secondary['payment_terms_days'] != 30) {
                $stmt = $this->db->prepare('UPDATE clients SET payment_terms_days = ? WHERE id = ?');
                $stmt->execute([$secondary['payment_terms_days'], $primaryId]);
            }
            
            // 9b. Transfer billing/company details if primary doesn't have them
            $billingFields = ['billing_name', 'billing_tax_id', 'billing_eu_tax_id', 'billing_address', 'billing_city', 'billing_zip', 'billing_country', 'billing_bank_account'];
            foreach ($billingFields as $bf) {
                if (empty($primary[$bf]) && !empty($secondary[$bf])) {
                    $stmt = $this->db->prepare("UPDATE clients SET {$bf} = ? WHERE id = ?");
                    $stmt->execute([$secondary[$bf], $primaryId]);
                }
            }
            
            // 10. Save the secondary's domain as an alias of the primary
            //     so future emails from that domain route to the merged client
            $stmt = $this->db->prepare('
                INSERT IGNORE INTO client_domain_aliases (user_email, alias_domain, client_id, merged_from_name)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$userEmail, $secondary['domain'], $primaryId, $secondary['display_name']]);
            
            // Also transfer any existing aliases the secondary client had
            $stmt = $this->db->prepare('
                UPDATE client_domain_aliases SET client_id = ? WHERE client_id = ? AND user_email = ?
            ');
            $stmt->execute([$primaryId, $secondaryId, $userEmail]);
            
            // 11. Delete the secondary client
            $stmt = $this->db->prepare('DELETE FROM clients WHERE id = ? AND user_email = ?');
            $stmt->execute([$secondaryId, $userEmail]);
            
            $this->db->commit();
            
            // Recalculate status for merged client
            $this->recalculateStatus($primaryId);
            
            return $this->getClient($userEmail, $primaryId);
            
        } catch (\Exception $e) {
            $this->db->rollBack();
            error_log("ClientService mergeClients error: " . $e->getMessage());
            return null;
        }
    }
    
    // =========================================================================
    // DOMAIN ALIASES (MERGE TRACKING)
    // =========================================================================
    
    /**
     * Get all domain aliases for a client
     */
    public function getAliases(string $userEmail, int $clientId): array
    {
        try {
            $stmt = $this->db->prepare('
                SELECT * FROM client_domain_aliases 
                WHERE user_email = ? AND client_id = ?
                ORDER BY created_at DESC
            ');
            $stmt->execute([strtolower($userEmail), $clientId]);
            return $stmt->fetchAll() ?: [];
        } catch (\PDOException $e) {
            error_log("ClientService getAliases error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Remove a domain alias (allows the domain to create its own client again)
     */
    public function removeAlias(string $userEmail, int $aliasId): bool
    {
        try {
            $stmt = $this->db->prepare('
                DELETE FROM client_domain_aliases 
                WHERE id = ? AND user_email = ?
            ');
            $stmt->execute([$aliasId, strtolower($userEmail)]);
            return $stmt->rowCount() > 0;
        } catch (\PDOException $e) {
            error_log("ClientService removeAlias error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Backfill domain aliases from existing contact data.
     * Looks at each client's contacts - if a contact's domain differs from
     * the client's primary domain, that domain should be an alias.
     * Only adds aliases for non-generic domains that don't already have their own client.
     */
    public function backfillAliases(string $userEmail): array
    {
        $userEmail = strtolower($userEmail);
        $created = 0;
        $skipped = 0;
        
        try {
            // Get all clients for this user
            $stmt = $this->db->prepare('SELECT id, domain, display_name FROM clients WHERE user_email = ?');
            $stmt->execute([$userEmail]);
            $clients = $stmt->fetchAll();
            
            // Build a set of existing client domains for quick lookup
            $existingDomains = [];
            foreach ($clients as $c) {
                $existingDomains[strtolower($c['domain'])] = (int)$c['id'];
            }
            
            // Get existing aliases
            $stmt = $this->db->prepare('SELECT alias_domain FROM client_domain_aliases WHERE user_email = ?');
            $stmt->execute([$userEmail]);
            $existingAliases = [];
            while ($row = $stmt->fetch()) {
                $existingAliases[strtolower($row['alias_domain'])] = true;
            }
            
            foreach ($clients as $client) {
                $clientId = (int)$client['id'];
                $clientDomain = strtolower($client['domain']);
                
                // Get all contacts for this client
                $stmt = $this->db->prepare('SELECT email FROM client_contacts WHERE client_id = ?');
                $stmt->execute([$clientId]);
                $contacts = $stmt->fetchAll();
                
                $seenDomains = [];
                foreach ($contacts as $contact) {
                    $contactEmail = strtolower($contact['email']);
                    $parts = explode('@', $contactEmail);
                    if (count($parts) !== 2) continue;
                    
                    $contactDomain = $parts[1];
                    
                    // Skip if same as client's primary domain
                    if ($contactDomain === $clientDomain) continue;
                    
                    // Skip generic domains
                    if ($this->isGenericDomain($contactDomain)) continue;
                    
                    // Skip if already processed in this loop
                    if (isset($seenDomains[$contactDomain])) continue;
                    $seenDomains[$contactDomain] = true;
                    
                    // Skip if this domain already has its own client
                    if (isset($existingDomains[$contactDomain])) {
                        $skipped++;
                        continue;
                    }
                    
                    // Skip if alias already exists
                    if (isset($existingAliases[$contactDomain])) {
                        $skipped++;
                        continue;
                    }
                    
                    // This contact's domain has no client of its own - it was likely merged
                    $stmt2 = $this->db->prepare('
                        INSERT IGNORE INTO client_domain_aliases (user_email, alias_domain, client_id, merged_from_name)
                        VALUES (?, ?, ?, ?)
                    ');
                    $stmt2->execute([$userEmail, $contactDomain, $clientId, ucfirst(explode('.', $contactDomain)[0])]);
                    
                    if ($stmt2->rowCount() > 0) {
                        $created++;
                        $existingAliases[$contactDomain] = true;
                        error_log("Backfill alias: {$contactDomain} -> client #{$clientId} ({$client['display_name']})");
                    }
                }
            }
            
            return ['created' => $created, 'skipped' => $skipped];
            
        } catch (\Exception $e) {
            error_log("ClientService backfillAliases error: " . $e->getMessage());
            return ['created' => $created, 'skipped' => $skipped, 'error' => $e->getMessage()];
        }
    }
    
    // =========================================================================
    // SIGNATURE EXTRACTION
    // =========================================================================
    
    /**
     * Extract phone and address from email signature text
     * Returns array with 'phone' and 'address' keys
     */
    public function extractSignatureInfo(string $signatureText): array
    {
        $result = [
            'phone' => null,
            'position' => null,
            'address' => null,
            'company' => null,
            'website' => null,
        ];
        
        if (empty($signatureText)) {
            return $result;
        }
        
        $result['phone'] = $this->extractPhone($signatureText);
        $result['address'] = $this->extractAddress($signatureText);
        $result['website'] = $this->extractWebsite($signatureText);
        
        return $result;
    }
    
    /**
     * Extract phone number from text using patterns from real Hungarian/international signatures
     */
    private function extractPhone(string $text): ?string
    {
        $phonePatterns = [
            // Hungarian with +36 prefix, various separators: +36 30 502 0597, +36(30)820-6529, +36 1 487 2606
            '/(?:(?:Tel|Phone|Mobil|Mobile|Cell|Fax|T|M|P)\s*[.:]\s*)?((?:\+36)\s*[\(\s]?\d{1,2}[\)\s\-]*\d{2,4}[\s\-]*\d{2,4}[\s\-]*\d{0,4})/iu',
            // Hungarian 06xx format: 0670 416 6271, 0630-252-9535
            '/(?:(?:Tel|Phone|Mobil|Mobile|Cell|T|M|P)\s*[.:]\s*)?(06[\s\-]?\d{1,2}[\s\-]?\d{2,4}[\s\-]?\d{2,4}[\s\-]?\d{0,4})/iu',
            // International with + prefix (general): +1 555 123 4567
            '/(?:(?:Tel|Phone|Mobil|Mobile|Cell|Fax|T|M|P)\s*[.:]\s*)?(\+\d[\d\s\-\(\)]{7,19})/iu',
            // Standalone number on its own line that looks like a phone (7+ digits with separators)
            '/^[\s]*(\(?\d{2,4}\)?[\s\-]\d{3,4}[\s\-]\d{3,4}[\s\-]?\d{0,4})[\s]*$/m',
        ];
        
        foreach ($phonePatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $phone = trim($matches[1]);
                $digits = preg_replace('/\D/', '', $phone);
                if (strlen($digits) >= 7 && strlen($digits) <= 15) {
                    return $phone;
                }
            }
        }
        return null;
    }
    
    /**
     * Extract ALL phone numbers from text
     */
    private function extractAllPhones(string $text): array
    {
        $phones = [];
        $phonePatterns = [
            '/(?:(?:Tel|Phone|Mobil|Mobile|Cell|Fax|T|M|P)\s*[.:]\s*)?((?:\+36)\s*[\(\s]?\d{1,2}[\)\s\-]*\d{2,4}[\s\-]*\d{2,4}[\s\-]*\d{0,4})/iu',
            '/(06[\s\-]?\d{1,2}[\s\-]?\d{2,4}[\s\-]?\d{2,4}[\s\-]?\d{0,4})/iu',
            '/(?:(?:Tel|Phone|Mobil|Mobile|Cell|Fax|T|M|P)\s*[.:]\s*)?(\+\d[\d\s\-\(\)]{7,19})/iu',
        ];
        
        foreach ($phonePatterns as $pattern) {
            if (preg_match_all($pattern, $text, $matches)) {
                foreach ($matches[1] as $phone) {
                    $phone = trim($phone);
                    $digits = preg_replace('/\D/', '', $phone);
                    if (strlen($digits) >= 7 && strlen($digits) <= 15) {
                        $phones[$digits] = $phone;
                    }
                }
            }
        }
        return array_values($phones);
    }
    
    /**
     * Extract physical address from text
     */
    private function extractAddress(string $text): ?string
    {
        $addressPatterns = [
            // Hungarian: 1146 Budapest, Istvánmezei út 6. or H-1023 Budapest, Lublói utca 2.
            '/([H\-]*\d{4}\s+[A-Za-zÁÉÍÓÖŐÚÜŰáéíóöőúüű]+[,.]?\s+[A-Za-zÁÉÍÓÖŐÚÜŰáéíóöőúüű\s\d\.\-]+(?:utca|u\.|út|tér|köz|sor|körút|krt|sétány|rakpart)[^\r\n]{0,30})/iu',
            // Hungarian with street first: Tigris utca 37.
            '/(\d{4}\s+[A-Za-zÁÉÍÓÖŐÚÜŰáéíóöőúüű\s,]+\d+[a-z]?\.?(?:\s*\([^)]+\))?)/iu',
            // US format
            '/(\d+\s+[A-Za-z\s]+(?:Street|St|Avenue|Ave|Road|Rd|Boulevard|Blvd|Drive|Dr|Lane|Ln)[,\s]+[A-Za-z\s]+,?\s*[A-Z]{2}\s*\d{5}(?:-\d{4})?)/i',
        ];
        
        foreach ($addressPatterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                $address = trim($matches[1]);
                $address = rtrim($address, ',. ');
                if (strlen($address) > 10) {
                    return $address;
                }
            }
        }
        return null;
    }
    
    /**
     * Extract website URL from text
     */
    private function extractWebsite(string $text): ?string
    {
        // Match www.domain.xx or http(s)://domain.xx patterns, but not email addresses
        if (preg_match('/(?:^|\s)((?:https?:\/\/)?(?:www\.)?[a-z0-9][\w\-]*\.[a-z]{2,}(?:\.[a-z]{2,})?)/im', $text, $matches)) {
            $url = trim($matches[1]);
            if (strpos($url, '@') === false && strlen($url) > 4) {
                return $url;
            }
        }
        return null;
    }
    
    /**
     * Extract position/job title from signature lines near a contact's name.
     * Looks for short text lines that aren't phone/email/url/address patterns.
     */
    private function extractPosition(array $contextLines): ?string
    {
        // Common Hungarian and English job title keywords
        $titleKeywords = '/(?:vezető|igazgató|ügyvezető|menedzser|manager|director|officer|head|lead|koordinátor|coordinator|tanácsadó|consultant|asszisztens|assistant|titkár|secretary|munkatárs|associate|referens|specialist|architect|designer|tervező|fejlesztő|developer|engineer|mérnök|irodavezető|elnök|president|partner|tulajdonos|owner|alapító|founder|CEO|CFO|CTO|COO|CMO|VP|account|sales|marketing|HR|IT|PR)/iu';
        
        foreach ($contextLines as $line) {
            $line = trim($line);
            if (empty($line) || strlen($line) < 3 || strlen($line) > 120) {
                continue;
            }
            
            // Skip lines that are phone numbers, emails, URLs, addresses, or base64 data
            if (preg_match('/[\+\d\(\)]{7,}/', $line)) continue;
            if (preg_match('/@/', $line)) continue;
            if (preg_match('/(?:www\.|https?:\/\/|\.hu|\.com|\.eu|\.net|\.org)/i', $line)) continue;
            if (preg_match('/^\d{4}\s/', $line)) continue;
            if (preg_match('/^[A-Za-z0-9+\/=]{20,}$/', $line)) continue;
            if (preg_match('/^(?:Tel|Phone|Mobil|Fax|E-?mail|Web)\s*[.:]/i', $line)) continue;
            // Skip common closings
            if (preg_match('/^(?:Üdvözlettel|Best regards|Kind regards|Regards|Thanks|Sincerely|Cheers|Mit freundlichen)/iu', $line)) continue;
            
            // Check if line looks like a job title
            if (preg_match($titleKeywords, $line)) {
                return $line;
            }
        }
        return null;
    }
    
    /**
     * Extract company name from signature lines near a contact's name.
     */
    private function extractCompany(array $contextLines, string $contactEmail): ?string
    {
        // Common company suffixes
        $companySuffixes = '/(?:Kft\.?|Zrt\.?|Bt\.?|Nyrt\.?|Ltd\.?|Inc\.?|LLC|GmbH|AG|s\.r\.o\.?|Group|Studio|Agency|Digital)/iu';
        
        foreach ($contextLines as $line) {
            $line = trim($line);
            if (empty($line) || strlen($line) < 3 || strlen($line) > 100) {
                continue;
            }
            
            // Skip phone, email, URL, address, base64 lines
            if (preg_match('/[\+\d\(\)]{7,}/', $line)) continue;
            if (preg_match('/@/', $line)) continue;
            if (preg_match('/^\d{4}\s/', $line)) continue;
            if (preg_match('/^[A-Za-z0-9+\/=]{20,}$/', $line)) continue;
            
            if (preg_match($companySuffixes, $line)) {
                // Clean up: remove leading labels
                $company = preg_replace('/^(?:Company|Cég|Vállalat)\s*[.:]\s*/iu', '', $line);
                return trim($company);
            }
        }
        return null;
    }
    
    /**
     * Strip quoted reply sections from an email body.
     * Keeps only the sender's own text (before the quoted reply chain).
     * This prevents extracting the logged-in user's own signature data.
     */
    public function stripQuotedReplies(string $body): string
    {
        $body = preg_replace('/\r\n?/', "\n", $body);
        
        $quotePatterns = [
            // "On Feb 20, 2026 at 10:30, name@email.com wrote:" (English)
            '/^On\s+.{10,60}\s+wrote:\s*$/im',
            // "2026. febr. 20., name@email.com ezt írta:" (Hungarian)
            '/^\d{4}[\.\-].{5,40}(?:ezt\s+[ií]rta|wrote):\s*$/im',
            // Gmail-style: "---------- Forwarded message ----------"
            '/^-{5,}\s*(?:Forwarded|Továbbított)\s+(?:message|üzenet)\s*-{5,}/im',
            // Outlook-style: "From: name@email.com" section
            '/^(?:From|Feladó|Von)\s*:\s*[^\n]*@[^\n]*$/im',
            // "--- Original Message ---" / "--- Eredeti üzenet ---"
            '/^-{2,}\s*(?:Original|Eredeti)\s+(?:Message|üzenet)\s*-{2,}/im',
            // Outlook underscores divider
            '/^_{5,}\s*$/m',
            // Long dash separator lines (10+ dashes, common in Outlook/custom clients)
            '/^-{10,}\s*$/m',
            // Lines starting with ">" (standard quoting) - find first block of 3+ consecutive quoted lines
            '/(?:^>.*\n){3,}/m',
        ];
        
        // Minimum position: we need at least 100 chars of actual content before cutting.
        // This prevents cutting at separators that appear very early (e.g. in forwarded messages).
        $minPos = 100;
        $cutPos = strlen($body);
        
        foreach ($quotePatterns as $pattern) {
            if (preg_match($pattern, $body, $m, \PREG_OFFSET_CAPTURE)) {
                $pos = $m[0][1];
                if ($pos > $minPos && $pos < $cutPos) {
                    $cutPos = $pos;
                }
            }
        }
        
        if ($cutPos < strlen($body)) {
            $body = substr($body, 0, $cutPos);
        }
        
        return trim($body);
    }
    
    /**
     * Extract signature from email body
     * Looks for common signature delimiters and patterns
     */
    public function extractSignatureFromEmail(string $emailBody): string
    {
        $delimiters = [
            '/^--\s*$/m',
            '/^_{3,}$/m',
            '/^-{3,}$/m',
            '/^Best regards/im',
            '/^Kind regards/im',
            '/^Regards,?\s*$/im',
            '/^Thanks,?\s*$/im',
            '/^Thank you,?\s*$/im',
            '/^Sincerely/im',
            '/^Cheers,?\s*$/im',
            '/^Üdvözlettel[,:]?\s*$/im',
            '/^Tisztelettel[,:]?\s*$/im',
            '/^Köszönettel[,:]?\s*$/im',
            '/^Mit freundlichen/im',
            '/^Sent from my /im',
        ];
        
        foreach ($delimiters as $pattern) {
            if (preg_match($pattern, $emailBody, $matches, \PREG_OFFSET_CAPTURE)) {
                $signatureStart = $matches[0][1];
                return substr($emailBody, $signatureStart);
            }
        }
        
        if (strlen($emailBody) > 800) {
            return substr($emailBody, -800);
        }
        
        return $emailBody;
    }
    
    /**
     * Name-aware signature extraction.
     * Finds the contact's name in the email body and extracts surrounding lines.
     * Handles Hungarian (Last First) and Western (First Last) name ordering.
     * Returns the extracted context block as lines, or falls back to regular signature extraction.
     */
    public function extractSignatureBlockForContact(string $emailBody, string $contactName, string $contactEmail): array
    {
        $result = [
            'phone' => null,
            'position' => null,
            'address' => null,
            'company' => null,
            'website' => null,
        ];
        
        if (empty($emailBody) || (empty($contactName) && empty($contactEmail))) {
            return $result;
        }
        
        // Clean and normalize the body for text extraction
        $body = html_entity_decode($emailBody, ENT_QUOTES, 'UTF-8');
        $body = preg_replace('/[\x{200B}-\x{200F}\x{2028}\x{2029}\x{202A}-\x{202E}\x{FEFF}\x{00AD}]/u', '', $body);
        $body = str_replace("\xc2\xa0", ' ', $body);
        $body = preg_replace('/\r\n?/', "\n", $body);
        // Collapse whitespace-only lines so name parts aren't pushed apart
        $body = preg_replace('/\n[ \t]+\n/', "\n\n", $body);
        $body = preg_replace('/\n{3,}/', "\n\n", $body);
        $lines = explode("\n", $body);
        
        // Build name variants to search for (handle Hungarian Last-First vs Western First-Last)
        $nameVariants = $this->buildNameVariants($contactName);
        $nameParts = preg_split('/\s+/', trim($contactName));
        $nameParts = array_filter($nameParts, fn($p) => mb_strlen($p) >= 2);
        
        // Strategy 1: Full name on a single line
        $nameLineIndex = null;
        foreach ($lines as $idx => $line) {
            $cleanLine = trim($line);
            if (empty($cleanLine)) continue;
            
            foreach ($nameVariants as $variant) {
                if (mb_stripos($cleanLine, $variant) !== false) {
                    $nameLineIndex = $idx;
                    break 2;
                }
            }
        }
        
        // Strategy 2: Name parts on nearby lines (e.g. "SIMON\n\nIZABELLA" split across lines)
        if ($nameLineIndex === null && count($nameParts) >= 2) {
            foreach ($lines as $idx => $line) {
                $cleanLine = trim($line);
                if (empty($cleanLine)) continue;
                
                foreach ($nameParts as $part) {
                    if (mb_stripos($cleanLine, $part) !== false && mb_strlen($cleanLine) < 40) {
                        $otherParts = array_filter($nameParts, fn($p) => mb_strtolower($p) !== mb_strtolower($part));
                        foreach ($otherParts as $otherPart) {
                            for ($offset = -5; $offset <= 5; $offset++) {
                                if ($offset === 0) continue;
                                $adjIdx = $idx + $offset;
                                if (isset($lines[$adjIdx]) && mb_stripos(trim($lines[$adjIdx]), $otherPart) !== false) {
                                    $nameLineIndex = min($idx, $adjIdx);
                                    break 4;
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Strategy 3: Match by the local part of their email address
        if ($nameLineIndex === null && !empty($contactEmail)) {
            $localPart = explode('@', $contactEmail)[0] ?? '';
            $emailNameParts = preg_split('/[._\-]/', $localPart);
            $emailNameParts = array_filter($emailNameParts, fn($p) => strlen($p) >= 3);
            
            if (count($emailNameParts) >= 2) {
                // Try single line first
                foreach ($lines as $idx => $line) {
                    $cleanLine = trim($line);
                    $matchCount = 0;
                    foreach ($emailNameParts as $part) {
                        if (mb_stripos($cleanLine, $part) !== false) {
                            $matchCount++;
                        }
                    }
                    if ($matchCount >= 2) {
                        $nameLineIndex = $idx;
                        break;
                    }
                }
                
                // Try nearby lines
                if ($nameLineIndex === null) {
                    foreach ($lines as $idx => $line) {
                        $cleanLine = trim($line);
                        if (empty($cleanLine)) continue;
                        foreach ($emailNameParts as $part) {
                            if (mb_stripos($cleanLine, $part) !== false && mb_strlen($cleanLine) < 40) {
                                $otherParts = array_filter($emailNameParts, fn($p) => strtolower($p) !== strtolower($part));
                                foreach ($otherParts as $otherPart) {
                                    for ($offset = -5; $offset <= 5; $offset++) {
                                        if ($offset === 0) continue;
                                        $adjIdx = $idx + $offset;
                                        if (isset($lines[$adjIdx]) && mb_stripos(trim($lines[$adjIdx]), $otherPart) !== false) {
                                            $nameLineIndex = min($idx, $adjIdx);
                                            break 4;
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        
        // Strategy 4: Find the contact's email address in the body (signatures often contain it)
        if ($nameLineIndex === null && !empty($contactEmail)) {
            foreach ($lines as $idx => $line) {
                if (mb_stripos(trim($line), $contactEmail) !== false) {
                    // Found their email in the body - scan upward to find the start of the signature block
                    $nameLineIndex = max(0, $idx - 8);
                    break;
                }
            }
        }
        
        if ($nameLineIndex === null) {
            error_log("[ExtractSig] No name match for '{$contactName}' / '{$contactEmail}' in " . count($lines) . " lines. First 5 non-empty: " . 
                implode(' | ', array_slice(array_filter(array_map('trim', $lines), fn($l) => !empty($l)), 0, 5)));
            
            // Fall back: try signature delimiter first, then scan the entire stripped body
            $signature = $this->extractSignatureFromEmail($emailBody);
            $info = $this->extractSignatureInfo($signature);
            // If signature extraction also found nothing, scan the full body as last resort
            if (empty($info['phone'])) {
                $info['phone'] = $this->extractPhone($body);
            }
            if (empty($info['address'])) {
                $info['address'] = $this->extractAddress($body);
            }
            if (empty($info['website'])) {
                $info['website'] = $this->extractWebsite($body);
            }
            return $info;
        }
        
        error_log("[ExtractSig] Found name '{$contactName}' at line {$nameLineIndex}: '" . trim($lines[$nameLineIndex] ?? '') . "'");
        
        // Extract context: lines around the name (signature blocks are typically 3-15 lines)
        $startIdx = max(0, $nameLineIndex - 2);
        $endIdx = min(count($lines) - 1, $nameLineIndex + 15);
        
        $contextLines = [];
        for ($i = $startIdx; $i <= $endIdx; $i++) {
            $line = trim($lines[$i]);
            if (!empty($line)) {
                $contextLines[] = $line;
            }
        }
        
        $contextText = implode("\n", $contextLines);
        
        // Lines AFTER the name are most likely to contain position, phone, etc.
        $linesAfterName = [];
        for ($i = $nameLineIndex + 1; $i <= $endIdx; $i++) {
            $line = trim($lines[$i] ?? '');
            if (!empty($line)) {
                $linesAfterName[] = $line;
            }
        }
        
        // Extract each field from the context
        $result['phone'] = $this->extractPhone($contextText);
        $result['address'] = $this->extractAddress($contextText);
        $result['website'] = $this->extractWebsite($contextText);
        
        // Position: look in the first few lines after the name
        $positionCandidates = array_slice($linesAfterName, 0, 4);
        $result['position'] = $this->extractPosition($positionCandidates);
        
        // Company: search in the context lines after name
        $result['company'] = $this->extractCompany($linesAfterName, $contactEmail);
        
        // Last resort: if context extraction missed the phone, scan the full body
        if (empty($result['phone'])) {
            $result['phone'] = $this->extractPhone($body);
        }
        if (empty($result['address'])) {
            $result['address'] = $this->extractAddress($body);
        }
        
        return $result;
    }
    
    /**
     * Build name variants for matching (handles Hungarian Last-First naming)
     */
    private function buildNameVariants(string $name): array
    {
        $name = trim($name);
        if (empty($name)) return [];
        
        $variants = [$name];
        
        $parts = preg_split('/\s+/', $name);
        if (count($parts) >= 2) {
            $variants[] = implode(' ', $parts);
            $reversed = array_reverse($parts);
            $variants[] = implode(' ', $reversed);
            
            if (count($parts) > 2) {
                $variants[] = $parts[0] . ' ' . end($parts);
                $variants[] = end($parts) . ' ' . $parts[0];
            }
        }
        
        // Add accent-stripped variants (TÓTH -> TOTH, Ákos -> Akos)
        $stripped = $this->stripAccents($name);
        if ($stripped !== $name) {
            $variants[] = $stripped;
            $strippedParts = preg_split('/\s+/', $stripped);
            if (count($strippedParts) >= 2) {
                $variants[] = implode(' ', array_reverse($strippedParts));
            }
        }
        
        return array_unique(array_filter($variants));
    }
    
    private function stripAccents(string $text): string
    {
        $map = [
            'á' => 'a', 'Á' => 'A', 'é' => 'e', 'É' => 'E',
            'í' => 'i', 'Í' => 'I', 'ó' => 'o', 'Ó' => 'O',
            'ö' => 'o', 'Ö' => 'O', 'ő' => 'o', 'Ő' => 'O',
            'ú' => 'u', 'Ú' => 'U', 'ü' => 'u', 'Ü' => 'U',
            'ű' => 'u', 'Ű' => 'U',
        ];
        return strtr($text, $map);
    }
    
    /**
     * Parse signature info from an email and update client
     */
    public function updateClientFromSignature(string $userEmail, int $clientId, string $emailBody): ?array
    {
        $signature = $this->extractSignatureFromEmail($emailBody);
        $info = $this->extractSignatureInfo($signature);
        
        $updates = [];
        if ($info['phone']) {
            $updates['phone'] = $info['phone'];
        }
        if ($info['address']) {
            $updates['address'] = $info['address'];
        }
        
        if (empty($updates)) {
            return $this->getClient($userEmail, $clientId);
        }
        
        return $this->updateClientInfo($userEmail, $clientId, $updates);
    }
    
    /**
     * Get recent email references from the database for a given domain.
     * Returns folder + uid pairs so the caller can fetch bodies via IMAP.
     * This is more reliable than doing a live IMAP FROM search.
     */
    public function getRecentEmailRefsForDomain(string $userEmail, string $domain, int $limit = 30): array
    {
        $conditions = [];
        $params = [strtolower($userEmail)];
        
        // If domain contains @, it's a full email address (generic provider)
        if (strpos($domain, '@') !== false) {
            $conditions[] = 'cm.from_email = ?';
            $params[] = strtolower($domain);
        } elseif (!$this->isGenericDomain($domain)) {
            // Business domain - match all emails from this domain
            $conditions[] = 'cm.from_email LIKE ?';
            $params[] = '%@' . strtolower($domain);
        } else {
            return [];
        }
        
        $whereClause = implode(' OR ', $conditions);
        
        try {
            $stmt = $this->db->prepare("
                SELECT cm.from_email, cm.from_name, fi.current_path AS folder,
                       cm.uid, cm.message_date
                FROM webmail_conversation_members cm
                LEFT JOIN webmail_folder_identity fi ON fi.id = cm.folder_id
                WHERE cm.user_email = ? AND ({$whereClause})
                ORDER BY cm.message_date DESC
                LIMIT ?
            ");
            $params[] = $limit;
            $stmt->execute($params);
            return $stmt->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Exception $e) {
            error_log("getRecentEmailRefsForDomain error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Extract contact info from multiple email bodies using name-aware pattern extraction.
     * Uses the contact name to find their signature block, then extracts phone, position, etc.
     * Falls back to pattern frequency analysis if name matching fails.
     * 
     * @param array $emailBodies Array of ['from_email', 'from_name', 'body']
     * @param array $contacts Optional existing contacts with name info for better matching
     * @param string $userEmail The logged-in user's email (to exclude their own signature data)
     */
    public function extractContactInfoFromEmails(array $emailBodies, array $contacts = [], string $userEmail = ''): array
    {
        // Build a lookup of contact names by email
        $contactNamesByEmail = [];
        foreach ($contacts as $c) {
            $ce = strtolower(trim($c['email'] ?? ''));
            if ($ce && !empty($c['name'])) {
                $contactNamesByEmail[$ce] = $c['name'];
            }
        }
        
        // Group emails by sender (skip emails FROM the logged-in user)
        $normalizedUserEmail = strtolower(trim($userEmail));
        $bySender = [];
        $skippedUserEmails = 0;
        foreach ($emailBodies as $entry) {
            $senderEmail = strtolower(trim($entry['from_email'] ?? ''));
            if (!$senderEmail) continue;
            if ($senderEmail === $normalizedUserEmail) {
                $skippedUserEmails++;
                continue;
            }
            $bySender[$senderEmail][] = $entry;
        }
        
        error_log("[ExtractInfo] Total bodies: " . count($emailBodies) . 
            ", user={$normalizedUserEmail}, skipped user's own={$skippedUserEmails}" .
            ", senders=" . implode(', ', array_keys($bySender)));
        
        $results = [];
        
        foreach ($bySender as $senderEmail => $emails) {
            $phones = [];
            $positions = [];
            $addresses = [];
            $companies = [];
            $websites = [];
            
            $contactName = $contactNamesByEmail[$senderEmail] 
                ?? $emails[0]['from_name'] 
                ?? '';
            
            $emailDebug = [];
            
            foreach ($emails as $emailIdx => $entry) {
                $body = $entry['body'] ?? '';
                if (empty($body)) continue;
                
                $bodyLenBefore = strlen($body);
                $body = $this->stripQuotedReplies($body);
                $bodyLenAfter = strlen($body);
                
                if (empty(trim($body))) {
                    $emailDebug[] = "#{$emailIdx}: stripped to empty (was {$bodyLenBefore})";
                    continue;
                }
                
                if (!empty($contactName)) {
                    $info = $this->extractSignatureBlockForContact($body, $contactName, $senderEmail);
                } else {
                    $signature = $this->extractSignatureFromEmail($body);
                    $info = $this->extractSignatureInfo($signature);
                }
                
                $dbgLine = "#{$emailIdx}: {$bodyLenBefore}->{$bodyLenAfter}ch, phone=" . ($info['phone'] ?? '-') . ", pos=" . ($info['position'] ?? '-');
                if (empty($info['phone'])) {
                    $dbgLine .= " | tail: " . preg_replace('/\s+/u', ' ', mb_substr(trim($body), -150));
                    // If +36 exists in body, show hex around it to detect invisible chars
                    $plusPos = strpos($body, '+36');
                    if ($plusPos !== false) {
                        $snippet = substr($body, max(0, $plusPos - 2), 25);
                        $dbgLine .= " | +36 hex: " . bin2hex($snippet);
                    }
                }
                $emailDebug[] = $dbgLine;
                
                if (!empty($info['phone'])) {
                    $normalized = preg_replace('/\D/', '', $info['phone']);
                    $phones[$normalized] = ($phones[$normalized] ?? 0) + 1;
                    if (!isset($phones['_fmt'][$normalized])) {
                        $phones['_fmt'][$normalized] = $info['phone'];
                    }
                }
                if (!empty($info['position'])) {
                    $normalizedPos = mb_strtolower(trim($info['position']));
                    $positions[$normalizedPos] = ($positions[$normalizedPos] ?? 0) + 1;
                    if (!isset($positions['_fmt'][$normalizedPos])) {
                        $positions['_fmt'][$normalizedPos] = $info['position'];
                    }
                }
                if (!empty($info['address'])) {
                    $normalizedAddr = mb_strtolower(preg_replace('/\s+/', ' ', trim($info['address'])));
                    $addresses[$normalizedAddr] = ($addresses[$normalizedAddr] ?? 0) + 1;
                    if (!isset($addresses['_fmt'][$normalizedAddr])) {
                        $addresses['_fmt'][$normalizedAddr] = $info['address'];
                    }
                }
                if (!empty($info['company'])) {
                    $normalizedCo = mb_strtolower(trim($info['company']));
                    $companies[$normalizedCo] = ($companies[$normalizedCo] ?? 0) + 1;
                    if (!isset($companies['_fmt'][$normalizedCo])) {
                        $companies['_fmt'][$normalizedCo] = $info['company'];
                    }
                }
                if (!empty($info['website'])) {
                    $normalizedWeb = strtolower(trim($info['website']));
                    $websites[$normalizedWeb] = ($websites[$normalizedWeb] ?? 0) + 1;
                }
            }
            
            $totalEmails = count($emails);
            
            $results[] = [
                'email' => $senderEmail,
                'name' => $contactName ?: ($emails[0]['from_name'] ?? null),
                'emails_scanned' => $totalEmails,
                'phone' => $this->pickBestResult($phones, $totalEmails),
                'phone_confidence' => $this->calcConfidence($phones, $totalEmails),
                'position' => $this->pickBestResult($positions, $totalEmails),
                'position_confidence' => $this->calcConfidence($positions, $totalEmails),
                'address' => $this->pickBestResult($addresses, $totalEmails),
                'address_confidence' => $this->calcConfidence($addresses, $totalEmails),
                'company' => $this->pickBestResult($companies, $totalEmails),
                'website' => $this->pickBestResultRaw($websites, $totalEmails),
                '_debug' => $emailDebug,
            ];
        }
        
        return $results;
    }
    
    /**
     * Pick the best (most frequent) result from frequency-counted candidates.
     * Expects array with normalized_key => count and '_fmt' => [normalized_key => formatted_value].
     */
    private function pickBestResult(array $candidates, int $totalEmails): ?string
    {
        $formatted = $candidates['_fmt'] ?? [];
        unset($candidates['_fmt']);
        
        if (empty($candidates)) return null;
        
        arsort($candidates);
        $topKey = array_key_first($candidates);
        
        // Always return the most frequent candidate - most emails are short replies
        // without signatures, so even a single occurrence is meaningful
        return $formatted[$topKey] ?? $topKey;
    }
    
    /**
     * Pick best result from simple key=>count array (no _fmt sub-array)
     */
    private function pickBestResultRaw(array $candidates, int $totalEmails): ?string
    {
        if (empty($candidates)) return null;
        arsort($candidates);
        $topKey = array_key_first($candidates);
        return $topKey;
    }
    
    /**
     * Calculate confidence score from frequency-counted candidates.
     * Most emails are short replies without signatures, so even 1-2 occurrences
     * of a phone/position across many emails is highly reliable.
     */
    private function calcConfidence(array $candidates, int $totalEmails): int
    {
        unset($candidates['_fmt']);
        if (empty($candidates)) return 0;
        
        arsort($candidates);
        $topCount = $candidates[array_key_first($candidates)];
        
        if ($totalEmails === 1) return 70;
        if ($topCount >= 3) return 95;
        if ($topCount >= 2) return 85;
        // Found once across multiple emails - still reliable (most replies lack signatures)
        return 65;
    }
    
    /**
     * Apply extracted contact info to contacts for a client.
     * Updates phone and position on client_contacts entries.
     */
    public function applyExtractedContactInfo(int $clientId, array $extractions): array
    {
        $applied = [];
        
        foreach ($extractions as $extraction) {
            $email = strtolower(trim($extraction['email'] ?? ''));
            if (!$email) continue;
            
            $setClauses = [];
            $params = [];
            
            if (!empty($extraction['phone'])) {
                $setClauses[] = 'phone = COALESCE(NULLIF(phone, ""), ?)';
                $params[] = $extraction['phone'];
            }
            if (!empty($extraction['position'])) {
                $setClauses[] = 'position = COALESCE(NULLIF(position, ""), ?)';
                $params[] = $extraction['position'];
            }
            
            if (empty($setClauses)) continue;
            
            try {
                $sql = 'UPDATE client_contacts SET ' . implode(', ', $setClauses)
                     . ' WHERE client_id = ? AND email = ?';
                $params[] = $clientId;
                $params[] = $email;
                
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                
                if ($stmt->rowCount() > 0) {
                    $applied[] = $email;
                }
            } catch (\PDOException $e) {
                error_log("ClientService applyExtractedContactInfo error: " . $e->getMessage());
            }
        }
        
        return $applied;
    }
    
    // =========================================================================
    // HELPER METHODS
    // =========================================================================
    
    /**
     * Get clients filtered to exclude associated accounts (for main client list)
     */
    public function getMainClients(string $userEmail, ?string $status = null, string $sortBy = 'status'): array
    {
        $userEmail = strtolower($userEmail);
        
        $sql = 'SELECT * FROM clients WHERE user_email = ? AND (is_associated = 0 OR is_associated IS NULL)';
        $params = [$userEmail];
        
        if ($status) {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        
        // Sort order
        switch ($sortBy) {
            case 'status':
                $sql .= " ORDER BY FIELD(status, 'attention', 'waiting', 'active'), last_activity_at DESC";
                break;
            case 'activity':
                $sql .= ' ORDER BY last_activity_at DESC';
                break;
            case 'name':
                $sql .= ' ORDER BY display_name ASC';
                break;
            default:
                $sql .= " ORDER BY FIELD(status, 'attention', 'waiting', 'active'), last_activity_at DESC";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $clients = $stmt->fetchAll();
        
        // Add contact count and associated count to each client
        foreach ($clients as &$client) {
            $countStmt = $this->db->prepare('SELECT COUNT(*) as count FROM client_contacts WHERE client_id = ?');
            $countStmt->execute([$client['id']]);
            $client['contact_count'] = (int)$countStmt->fetch()['count'];
            
            $assocStmt = $this->db->prepare('SELECT COUNT(*) as count FROM clients WHERE associated_with_client_id = ?');
            $assocStmt->execute([$client['id']]);
            $client['associated_count'] = (int)$assocStmt->fetch()['count'];
        }
        
        return $clients;
    }
    
    // =========================================================================
    // TEAM MEMBERSHIP METHODS
    // =========================================================================
    
    /**
     * Add a team member to a client
     */
    public function addMember(string $ownerEmail, int $clientId, string $memberEmail, string $role = 'member'): array
    {
        $ownerEmail = strtolower($ownerEmail);
        $memberEmail = strtolower($memberEmail);
        
        // Verify owner has access to this client
        $client = $this->getClient($ownerEmail, $clientId);
        if (!$client) {
            return ['success' => false, 'error' => 'Client not found'];
        }
        
        // Check if already a member
        $stmt = $this->db->prepare('SELECT id FROM client_members WHERE client_id = ? AND user_email = ?');
        $stmt->execute([$clientId, $memberEmail]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'User is already a member'];
        }
        
        try {
            $stmt = $this->db->prepare('
                INSERT INTO client_members (client_id, user_email, role, added_by)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$clientId, $memberEmail, $role, $ownerEmail]);
            
            return [
                'success' => true,
                'member' => [
                    'id' => (int)$this->db->lastInsertId(),
                    'client_id' => $clientId,
                    'user_email' => $memberEmail,
                    'role' => $role,
                    'added_by' => $ownerEmail,
                    'added_at' => date('Y-m-d H:i:s')
                ]
            ];
        } catch (\PDOException $e) {
            error_log("ClientService addMember error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to add member'];
        }
    }
    
    /**
     * Remove a team member from a client
     */
    public function removeMember(string $ownerEmail, int $clientId, string $memberEmail): bool
    {
        $ownerEmail = strtolower($ownerEmail);
        $memberEmail = strtolower($memberEmail);
        
        // Verify owner has access to this client
        $client = $this->getClient($ownerEmail, $clientId);
        if (!$client) {
            return false;
        }
        
        // Can't remove owner
        if ($memberEmail === $ownerEmail) {
            return false;
        }
        
        $stmt = $this->db->prepare('DELETE FROM client_members WHERE client_id = ? AND user_email = ?');
        $stmt->execute([$clientId, $memberEmail]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get all members of a client
     */
    public function getMembers(int $clientId): array
    {
        $stmt = $this->db->prepare('
            SELECT cm.*, c.user_email as owner_email
            FROM client_members cm
            JOIN clients c ON c.id = cm.client_id
            WHERE cm.client_id = ?
            ORDER BY cm.role DESC, cm.added_at ASC
        ');
        $stmt->execute([$clientId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Check if a user is a member of a client
     */
    /**
     * Check if a client exists (regardless of ownership)
     * Used for lightweight validation in time tracking where
     * clients are auto-detected via domain/board/folder mappings
     */
    public function clientExists(int $clientId): bool
    {
        $stmt = $this->db->prepare('SELECT id FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        return (bool)$stmt->fetch();
    }
    
    public function isMember(int $clientId, string $userEmail): bool
    {
        $userEmail = strtolower($userEmail);
        
        // Check if owner
        $stmt = $this->db->prepare('SELECT id FROM clients WHERE id = ? AND user_email = ?');
        $stmt->execute([$clientId, $userEmail]);
        if ($stmt->fetch()) {
            return true;
        }
        
        // Check if team member
        $stmt = $this->db->prepare('SELECT id FROM client_members WHERE client_id = ? AND user_email = ?');
        $stmt->execute([$clientId, $userEmail]);
        return (bool)$stmt->fetch();
    }
    
    /**
     * Get member's role for a client
     */
    public function getMemberRole(int $clientId, string $userEmail): ?string
    {
        $userEmail = strtolower($userEmail);
        
        // Check if owner
        $stmt = $this->db->prepare('SELECT id FROM clients WHERE id = ? AND user_email = ?');
        $stmt->execute([$clientId, $userEmail]);
        if ($stmt->fetch()) {
            return 'owner';
        }
        
        // Check team member role
        $stmt = $this->db->prepare('SELECT role FROM client_members WHERE client_id = ? AND user_email = ?');
        $stmt->execute([$clientId, $userEmail]);
        $result = $stmt->fetch();
        return $result ? $result['role'] : null;
    }
    
    /**
     * Get all clients where user is a member (not owner)
     * Used to show shared clients in user's client list
     */
    public function getSharedClients(string $userEmail): array
    {
        $userEmail = strtolower($userEmail);
        
        $stmt = $this->db->prepare('
            SELECT c.*, cm.role as member_role, cm.added_at as member_since
            FROM clients c
            JOIN client_members cm ON cm.client_id = c.id
            WHERE cm.user_email = ?
            ORDER BY c.last_activity_at DESC
        ');
        $stmt->execute([$userEmail]);
        return $stmt->fetchAll();
    }
    
    /**
     * Update a member's role
     */
    public function updateMemberRole(string $ownerEmail, int $clientId, string $memberEmail, string $newRole): bool
    {
        $ownerEmail = strtolower($ownerEmail);
        $memberEmail = strtolower($memberEmail);
        
        // Verify owner has access
        $client = $this->getClient($ownerEmail, $clientId);
        if (!$client) {
            return false;
        }
        
        $stmt = $this->db->prepare('UPDATE client_members SET role = ? WHERE client_id = ? AND user_email = ?');
        $stmt->execute([$newRole, $clientId, $memberEmail]);
        return $stmt->rowCount() > 0;
    }
}

