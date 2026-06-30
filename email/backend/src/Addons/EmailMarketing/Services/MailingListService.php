<?php

namespace Webmail\Addons\EmailMarketing\Services;

/**
 * MailingListService - External contact mailing lists
 * 
 * Features:
 * - Create and manage mailing lists
 * - Add/remove contacts with name, email, phone, position
 * - Import contacts from Excel/CSV
 * - Select lists as email recipients
 */
class MailingListService
{
    private \PDO $db;
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        \Webmail\Core\SchemaGuard::run(fn() => $this->ensureTablesExist());
    }
    
    /**
     * Ensure all required tables exist
     */
    private function ensureTablesExist(): void
    {
        try {
            // Mailing lists
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mailing_lists (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    organization_domain VARCHAR(255) NOT NULL,
                    name VARCHAR(255) NOT NULL,
                    description TEXT DEFAULT NULL,
                    color VARCHAR(20) DEFAULT '#6366f1',
                    icon VARCHAR(50) DEFAULT 'mail',
                    is_shared TINYINT(1) DEFAULT 0 COMMENT '0=private, 1=company-wide',
                    sort_order INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_user_name (user_email, name),
                    INDEX idx_user (user_email),
                    INDEX idx_domain_shared (organization_domain, is_shared),
                    INDEX idx_sort (user_email, sort_order)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Add columns if they don't exist (for existing tables)
            try {
                $this->db->exec("ALTER TABLE mailing_lists ADD COLUMN organization_domain VARCHAR(255) NOT NULL DEFAULT '' AFTER user_email");
            } catch (\Throwable $e) { /* Column may already exist */ }
            
            try {
                $this->db->exec("ALTER TABLE mailing_lists ADD COLUMN is_shared TINYINT(1) DEFAULT 0 AFTER icon");
            } catch (\Throwable $e) { /* Column may already exist */ }
            
            try {
                $this->db->exec("ALTER TABLE mailing_lists ADD INDEX idx_domain_shared (organization_domain, is_shared)");
            } catch (\Throwable $e) { /* Index may already exist */ }
            
            // Mailing list contacts
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mailing_list_contacts (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    list_id INT UNSIGNED NOT NULL,
                    email VARCHAR(255) NOT NULL,
                    name VARCHAR(255) DEFAULT NULL,
                    phone VARCHAR(50) DEFAULT NULL,
                    position VARCHAR(255) DEFAULT NULL,
                    company VARCHAR(255) DEFAULT NULL,
                    notes TEXT DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_list_email (list_id, email),
                    INDEX idx_email (email),
                    INDEX idx_name (name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Import history
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS mailing_list_imports (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    list_id INT UNSIGNED NOT NULL,
                    user_email VARCHAR(255) NOT NULL,
                    filename VARCHAR(255) DEFAULT NULL,
                    total_rows INT DEFAULT 0,
                    imported_count INT DEFAULT 0,
                    skipped_count INT DEFAULT 0,
                    error_count INT DEFAULT 0,
                    errors JSON DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_list (list_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
        } catch (\Throwable $e) {
            error_log("MailingListService table creation error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            error_log("Stack trace: " . $e->getTraceAsString());
            // Re-throw to prevent silent failures
            throw new \RuntimeException("Failed to initialize mailing list tables: " . $e->getMessage(), 0, $e);
        }
    }
    
    // =========================================================================
    // HELPERS
    // =========================================================================
    
    /**
     * Extract domain from email address
     */
    private function getDomain(string $email): string
    {
        return strtolower(substr($email, strpos($email, '@') + 1));
    }
    
    // =========================================================================
    // MAILING LISTS
    // =========================================================================
    
    /**
     * Get all mailing lists for a user (own private + all shared from organization)
     */
    public function getLists(string $userEmail): array
    {
        $domain = $this->getDomain($userEmail);
        
        // Get user's own lists (private) + all shared lists from their organization
        $stmt = $this->db->prepare("
            SELECT ml.*, 
                   COUNT(DISTINCT mlc.id) as contact_count,
                   CASE WHEN ml.user_email = ? THEN 1 ELSE 0 END as is_owner
            FROM mailing_lists ml
            LEFT JOIN mailing_list_contacts mlc ON mlc.list_id = ml.id
            WHERE ml.user_email = ?
               OR (ml.organization_domain = ? AND ml.is_shared = 1)
            GROUP BY ml.id
            ORDER BY ml.is_shared ASC, ml.sort_order ASC, ml.name ASC
        ");
        $stmt->execute([$userEmail, $userEmail, $domain]);
        
        return array_map(function($row) {
            $row['is_shared'] = (int)($row['is_shared'] ?? 0);
            $row['is_owner'] = (int)($row['is_owner'] ?? 0);
            $row['contact_count'] = (int)($row['contact_count'] ?? 0);
            return $row;
        }, $stmt->fetchAll());
    }
    
    /**
     * Get a single mailing list with contacts
     */
    public function getList(int $listId, string $userEmail): ?array
    {
        $domain = $this->getDomain($userEmail);
        
        // Can access if owner OR if shared and same organization
        $stmt = $this->db->prepare("
            SELECT *, 
                   CASE WHEN user_email = ? THEN 1 ELSE 0 END as is_owner
            FROM mailing_lists 
            WHERE id = ? AND (user_email = ? OR (organization_domain = ? AND is_shared = 1))
        ");
        $stmt->execute([$userEmail, $listId, $userEmail, $domain]);
        $list = $stmt->fetch();
        
        if (!$list) {
            return null;
        }
        
        $list['is_shared'] = (int)($list['is_shared'] ?? 0);
        $list['is_owner'] = (int)($list['is_owner'] ?? 0);
        
        // Get contacts
        $stmt = $this->db->prepare("
            SELECT * FROM mailing_list_contacts 
            WHERE list_id = ?
            ORDER BY name ASC, email ASC
        ");
        $stmt->execute([$listId]);
        $list['contacts'] = $stmt->fetchAll();
        
        return $list;
    }
    
    /**
     * Create a new mailing list
     */
    public function createList(string $userEmail, array $data): int
    {
        $domain = $this->getDomain($userEmail);
        $isShared = !empty($data['is_shared']) ? 1 : 0;
        
        $stmt = $this->db->prepare("
            INSERT INTO mailing_lists (user_email, organization_domain, name, description, color, icon, is_shared, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userEmail,
            $domain,
            $data['name'],
            $data['description'] ?? null,
            $data['color'] ?? '#6366f1',
            $data['icon'] ?? 'mail',
            $isShared,
            $data['sort_order'] ?? 0
        ]);
        
        return (int) $this->db->lastInsertId();
    }
    
    /**
     * Update a mailing list
     */
    public function updateList(int $listId, string $userEmail, array $data): bool
    {
        $fields = [];
        $values = [];
        
        foreach (['name', 'description', 'color', 'icon', 'is_shared', 'sort_order'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                // Convert is_shared to integer
                if ($field === 'is_shared') {
                    $values[] = $data[$field] ? 1 : 0;
                } else {
                    $values[] = $data[$field];
                }
            }
        }
        
        if (empty($fields)) {
            return true;
        }
        
        $values[] = $listId;
        $values[] = $userEmail;
        
        // Only owner can update the list
        $stmt = $this->db->prepare("
            UPDATE mailing_lists 
            SET " . implode(', ', $fields) . "
            WHERE id = ? AND user_email = ?
        ");
        
        return $stmt->execute($values);
    }
    
    /**
     * Delete a mailing list
     */
    public function deleteList(int $listId, string $userEmail): bool
    {
        $stmt = $this->db->prepare("
            DELETE FROM mailing_lists 
            WHERE id = ? AND user_email = ?
        ");
        return $stmt->execute([$listId, $userEmail]);
    }
    
    // =========================================================================
    // CONTACTS
    // =========================================================================
    
    /**
     * Get contacts for a mailing list
     */
    public function getContacts(int $listId, string $userEmail): array
    {
        // Verify ownership
        $stmt = $this->db->prepare("
            SELECT id FROM mailing_lists WHERE id = ? AND user_email = ?
        ");
        $stmt->execute([$listId, $userEmail]);
        if (!$stmt->fetch()) {
            return [];
        }
        
        $stmt = $this->db->prepare("
            SELECT * FROM mailing_list_contacts 
            WHERE list_id = ?
            ORDER BY name ASC, email ASC
        ");
        $stmt->execute([$listId]);
        return $stmt->fetchAll();
    }
    
    /**
     * Add a contact to a mailing list
     */
    public function addContact(int $listId, string $userEmail, array $data): ?int
    {
        // Verify ownership
        $stmt = $this->db->prepare("
            SELECT id FROM mailing_lists WHERE id = ? AND user_email = ?
        ");
        $stmt->execute([$listId, $userEmail]);
        if (!$stmt->fetch()) {
            return null;
        }
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO mailing_list_contacts (list_id, email, name, phone, position, company, notes, custom_fields)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $listId,
                $data['email'],
                $data['name'] ?? null,
                $data['phone'] ?? null,
                $data['position'] ?? null,
                $data['company'] ?? null,
                $data['notes'] ?? null,
                isset($data['custom_fields']) ? json_encode($data['custom_fields']) : null,
            ]);
            
            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            // Duplicate email in list
            if ($e->getCode() == 23000) {
                return null;
            }
            throw $e;
        }
    }
    
    /**
     * Update a contact
     */
    public function updateContact(int $contactId, string $userEmail, array $data): bool
    {
        // Verify ownership through list
        $stmt = $this->db->prepare("
            SELECT mlc.id FROM mailing_list_contacts mlc
            JOIN mailing_lists ml ON ml.id = mlc.list_id
            WHERE mlc.id = ? AND ml.user_email = ?
        ");
        $stmt->execute([$contactId, $userEmail]);
        if (!$stmt->fetch()) {
            return false;
        }
        
        $fields = [];
        $values = [];
        
        foreach (['email', 'name', 'phone', 'position', 'company', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $data[$field];
            }
        }
        
        if (array_key_exists('custom_fields', $data)) {
            $fields[] = "custom_fields = ?";
            $values[] = json_encode($data['custom_fields']);
        }
        
        if (empty($fields)) {
            return true;
        }
        
        $values[] = $contactId;
        
        $stmt = $this->db->prepare("
            UPDATE mailing_list_contacts 
            SET " . implode(', ', $fields) . "
            WHERE id = ?
        ");
        
        return $stmt->execute($values);
    }
    
    /**
     * Delete a contact
     */
    public function deleteContact(int $contactId, string $userEmail): bool
    {
        // Verify ownership through list
        $stmt = $this->db->prepare("
            SELECT mlc.id FROM mailing_list_contacts mlc
            JOIN mailing_lists ml ON ml.id = mlc.list_id
            WHERE mlc.id = ? AND ml.user_email = ?
        ");
        $stmt->execute([$contactId, $userEmail]);
        if (!$stmt->fetch()) {
            return false;
        }
        
        $stmt = $this->db->prepare("DELETE FROM mailing_list_contacts WHERE id = ?");
        return $stmt->execute([$contactId]);
    }
    
    /**
     * Bulk delete contacts
     */
    public function deleteContacts(array $contactIds, string $userEmail): int
    {
        if (empty($contactIds)) {
            return 0;
        }
        
        $placeholders = implode(',', array_fill(0, count($contactIds), '?'));
        
        // Get contacts that belong to user's lists
        $stmt = $this->db->prepare("
            SELECT mlc.id FROM mailing_list_contacts mlc
            JOIN mailing_lists ml ON ml.id = mlc.list_id
            WHERE mlc.id IN ($placeholders) AND ml.user_email = ?
        ");
        $stmt->execute([...$contactIds, $userEmail]);
        $validIds = array_column($stmt->fetchAll(), 'id');
        
        if (empty($validIds)) {
            return 0;
        }
        
        $placeholders = implode(',', array_fill(0, count($validIds), '?'));
        $stmt = $this->db->prepare("DELETE FROM mailing_list_contacts WHERE id IN ($placeholders)");
        $stmt->execute($validIds);
        
        return $stmt->rowCount();
    }
    
    // =========================================================================
    // IMPORT
    // =========================================================================
    
    /**
     * Import contacts from parsed Excel/CSV data
     * Expected format: array of ['email' => ..., 'name' => ..., 'phone' => ..., 'position' => ...]
     */
    public function importContacts(int $listId, string $userEmail, array $contacts, ?string $filename = null): array
    {
        // Verify ownership
        $stmt = $this->db->prepare("
            SELECT id FROM mailing_lists WHERE id = ? AND user_email = ?
        ");
        $stmt->execute([$listId, $userEmail]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'List not found'];
        }
        
        $imported = 0;
        $skipped = 0;
        $errors = [];
        
        foreach ($contacts as $index => $contact) {
            $rowNum = $index + 2; // Excel rows start at 1, plus header
            
            // Validate email
            $email = trim($contact['email'] ?? '');
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Row $rowNum: Invalid or missing email";
                continue;
            }
            
            try {
                $stmt = $this->db->prepare("
                    INSERT INTO mailing_list_contacts (list_id, email, name, phone, position, company, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        phone = VALUES(phone),
                        position = VALUES(position),
                        company = VALUES(company),
                        notes = VALUES(notes)
                ");
                $stmt->execute([
                    $listId,
                    $email,
                    $contact['name'] ?? null,
                    $contact['phone'] ?? null,
                    $contact['position'] ?? null,
                    $contact['company'] ?? null,
                    $contact['notes'] ?? null
                ]);
                
                if ($stmt->rowCount() > 0) {
                    $imported++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Row $rowNum: " . $e->getMessage();
            }
        }
        
        // Log import
        $stmt = $this->db->prepare("
            INSERT INTO mailing_list_imports 
            (list_id, user_email, filename, total_rows, imported_count, skipped_count, error_count, errors)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $listId,
            $userEmail,
            $filename,
            count($contacts),
            $imported,
            $skipped,
            count($errors),
            !empty($errors) ? json_encode($errors) : null
        ]);
        
        return [
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors
        ];
    }
    
    /**
     * Parse Excel/CSV file content
     * Returns array of contacts
     */
    public function parseExcelData(string $content, string $fileType = 'csv'): array
    {
        $contacts = [];
        
        if ($fileType === 'csv') {
            $lines = explode("\n", $content);
            $header = null;
            
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                
                $row = str_getcsv($line);
                
                if ($header === null) {
                    // First row is header - normalize column names
                    $header = array_map(function($col) {
                        $col = strtolower(trim($col));
                        // Map common variations
                        $mappings = [
                            'e-mail' => 'email',
                            'email address' => 'email',
                            'e-mail address' => 'email',
                            'full name' => 'name',
                            'phone number' => 'phone',
                            'telephone' => 'phone',
                            'mobile' => 'phone',
                            'job title' => 'position',
                            'title' => 'position',
                            'role' => 'position',
                            'organization' => 'company',
                            'organisation' => 'company',
                            'firm' => 'company',
                        ];
                        return $mappings[$col] ?? $col;
                    }, $row);
                    continue;
                }
                
                $contact = [];
                foreach ($row as $i => $value) {
                    if (isset($header[$i])) {
                        $contact[$header[$i]] = trim($value);
                    }
                }
                
                // Only add if there's an email
                if (!empty($contact['email'])) {
                    $contacts[] = $contact;
                }
            }
        }
        
        return $contacts;
    }
    
    // =========================================================================
    // SEARCH (for compose autocomplete)
    // =========================================================================
    
    /**
     * Search mailing lists by name
     */
    public function searchLists(string $userEmail, string $query): array
    {
        $stmt = $this->db->prepare("
            SELECT ml.*, COUNT(DISTINCT mlc.id) as contact_count
            FROM mailing_lists ml
            LEFT JOIN mailing_list_contacts mlc ON mlc.list_id = ml.id
            WHERE ml.user_email = ? AND ml.name LIKE ?
            GROUP BY ml.id
            ORDER BY ml.name ASC
            LIMIT 10
        ");
        $stmt->execute([$userEmail, "%$query%"]);
        return $stmt->fetchAll();
    }
    
    /**
     * Get all emails from a mailing list (for sending)
     */
    public function getListEmails(int $listId, string $userEmail): array
    {
        $domain = $this->getDomain($userEmail);
        
        // Verify access: owner OR shared list in same organization
        $stmt = $this->db->prepare("
            SELECT id FROM mailing_lists 
            WHERE id = ? AND (user_email = ? OR (organization_domain = ? AND is_shared = 1))
        ");
        $stmt->execute([$listId, $userEmail, $domain]);
        if (!$stmt->fetch()) {
            return [];
        }
        
        $stmt = $this->db->prepare("
            SELECT email, name, position FROM mailing_list_contacts 
            WHERE list_id = ?
            ORDER BY name ASC, email ASC
        ");
        $stmt->execute([$listId]);
        return $stmt->fetchAll();
    }
    
    // =========================================================================
    // CUSTOM FIELDS
    // =========================================================================
    
    public function getCustomFields(int $listId, string $userEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT id FROM mailing_lists WHERE id = ? AND user_email = ?
        ");
        $stmt->execute([$listId, $userEmail]);
        if (!$stmt->fetch()) {
            return [];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM mailing_list_custom_fields 
                WHERE list_id = ? ORDER BY sort_order ASC, id ASC
            ");
            $stmt->execute([$listId]);
            $fields = $stmt->fetchAll();
            foreach ($fields as &$f) {
                $f['options'] = json_decode($f['options'] ?? 'null', true);
            }
            return $fields;
        } catch (\Throwable $e) {
            return [];
        }
    }
    
    public function createCustomField(int $listId, string $userEmail, array $data): ?int
    {
        $stmt = $this->db->prepare("SELECT id FROM mailing_lists WHERE id = ? AND user_email = ?");
        $stmt->execute([$listId, $userEmail]);
        if (!$stmt->fetch()) return null;
        
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower(str_replace(' ', '_', $data['field_key'] ?? $data['field_label'] ?? '')));
        if (empty($key)) return null;
        
        try {
            $stmt = $this->db->prepare("
                INSERT INTO mailing_list_custom_fields (list_id, field_key, field_label, field_type, options, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $listId,
                $key,
                $data['field_label'] ?? $key,
                $data['field_type'] ?? 'text',
                !empty($data['options']) ? json_encode($data['options']) : null,
                $data['sort_order'] ?? 0,
            ]);
            return (int)$this->db->lastInsertId();
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) return null;
            throw $e;
        }
    }
    
    public function updateCustomField(int $fieldId, string $userEmail, array $data): bool
    {
        $stmt = $this->db->prepare("
            SELECT cf.id FROM mailing_list_custom_fields cf
            JOIN mailing_lists ml ON ml.id = cf.list_id
            WHERE cf.id = ? AND ml.user_email = ?
        ");
        $stmt->execute([$fieldId, $userEmail]);
        if (!$stmt->fetch()) return false;
        
        $fields = [];
        $values = [];
        foreach (['field_label', 'field_type', 'sort_order'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $values[] = $data[$f];
            }
        }
        if (array_key_exists('options', $data)) {
            $fields[] = "options = ?";
            $values[] = json_encode($data['options']);
        }
        if (empty($fields)) return true;
        
        $values[] = $fieldId;
        $stmt = $this->db->prepare("UPDATE mailing_list_custom_fields SET " . implode(', ', $fields) . " WHERE id = ?");
        return $stmt->execute($values);
    }
    
    public function deleteCustomField(int $fieldId, string $userEmail): bool
    {
        $stmt = $this->db->prepare("
            SELECT cf.id, cf.field_key, cf.list_id FROM mailing_list_custom_fields cf
            JOIN mailing_lists ml ON ml.id = cf.list_id
            WHERE cf.id = ? AND ml.user_email = ?
        ");
        $stmt->execute([$fieldId, $userEmail]);
        $field = $stmt->fetch();
        if (!$field) return false;
        
        $this->db->prepare("DELETE FROM mailing_list_custom_fields WHERE id = ?")->execute([$fieldId]);
        
        return true;
    }
}

