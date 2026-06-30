<?php

namespace Collab\Services;

/**
 * CollabDocumentService
 * 
 * Handles document CRUD operations and version management.
 */
class CollabDocumentService
{
    private \PDO $db;
    private string $prefix;
    
    public function __construct(array $config, string $prefix = 'collab_')
    {
        $this->prefix = $prefix;
        
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=utf8mb4',
            $config['db']['host'] ?? '127.0.0.1',
            $config['db']['name'] ?? 'devc_vps_dash'
        );
        
        $this->db = new \PDO(
            $dsn,
            $config['db']['user'] ?? 'vpsadmin',
            $config['db']['pass'] ?? '',
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]
        );
    }
    
    /**
     * List documents user has access to
     */
    public function listDocuments(string $email, ?string $type = null, int $limit = 50, int $offset = 0): array
    {
        $email = strtolower($email);
        
        $whereType = $type ? 'AND d.type = ?' : '';
        
        // Build params for UNION query - each SELECT needs its own set of params
        $params = [$email]; // First SELECT: p.user_email = ?
        if ($type) {
            $params[] = $type; // First SELECT: d.type = ?
        }
        $params[] = $email; // Second SELECT (UNION): d.owner_email = ?
        if ($type) {
            $params[] = $type; // Second SELECT (UNION): d.type = ?
        }
        
        // Sanitize limit/offset as integers (safe to embed directly)
        $limit = (int)$limit;
        $offset = (int)$offset;
        
        // Get documents where user has permission
        $sql = "
            SELECT DISTINCT d.id, d.uuid, d.title, d.type, d.owner_email, d.folder_id, d.created_at, d.updated_at,
                   p.role,
                   (SELECT COUNT(*) FROM {$this->prefix}permissions WHERE document_id = d.id) as collaborator_count
            FROM {$this->prefix}documents d
            JOIN {$this->prefix}permissions p ON d.id = p.document_id
            WHERE p.user_email = ?
              AND d.deleted_at IS NULL
              $whereType
            
            UNION
            
            SELECT d.id, d.uuid, d.title, d.type, d.owner_email, d.folder_id, d.created_at, d.updated_at,
                   'owner' as role,
                   (SELECT COUNT(*) FROM {$this->prefix}permissions WHERE document_id = d.id) as collaborator_count
            FROM {$this->prefix}documents d
            WHERE d.owner_email = ?
              AND d.deleted_at IS NULL
              $whereType
            
            ORDER BY updated_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $documents = $stmt->fetchAll();
        
        // Get total count
        $countSql = "
            SELECT COUNT(DISTINCT d.id) as total
            FROM {$this->prefix}documents d
            LEFT JOIN {$this->prefix}permissions p ON d.id = p.document_id
            WHERE (p.user_email = ? OR d.owner_email = ?)
              AND d.deleted_at IS NULL
              $whereType
        ";
        
        $countParams = [$email, $email];
        if ($type) {
            $countParams[] = $type;
        }
        
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($countParams);
        $total = (int)$stmt->fetchColumn();
        
        return [
            'documents' => $documents,
            'total' => $total,
        ];
    }
    
    /**
     * Get a single document
     */
    public function getDocument(string $uuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT uuid, title, type, owner_email, created_at, updated_at,
                   (SELECT COUNT(*) FROM {$this->prefix}permissions WHERE document_id = d.id) as collaborator_count
            FROM {$this->prefix}documents d
            WHERE uuid = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$uuid]);
        
        $doc = $stmt->fetch();
        return $doc ?: null;
    }
    
    /**
     * Create a new document
     */
    public function createDocument(string $ownerEmail, string $title, string $type, ?int $folderId = null): array
    {
        $ownerEmail = strtolower($ownerEmail);
        $uuid = $this->generateUuid();
        
        $this->db->beginTransaction();
        
        try {
            // Create document with optional folder_id
            $stmt = $this->db->prepare("
                INSERT INTO {$this->prefix}documents (uuid, owner_email, title, type, folder_id)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$uuid, $ownerEmail, $title, $type, $folderId]);
            
            $documentId = (int)$this->db->lastInsertId();
            
            // Add owner permission
            $stmt = $this->db->prepare("
                INSERT INTO {$this->prefix}permissions (document_id, user_email, role)
                VALUES (?, ?, 'owner')
            ");
            $stmt->execute([$documentId, $ownerEmail]);
            
            $this->db->commit();
            
            return [
                'uuid' => $uuid,
                'title' => $title,
                'type' => $type,
                'owner_email' => $ownerEmail,
                'folder_id' => $folderId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'collaborator_count' => 1,
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Update document metadata
     */
    public function updateDocument(string $uuid, array $updates): bool
    {
        $sets = [];
        $params = [];
        
        if (isset($updates['title'])) {
            $sets[] = 'title = ?';
            $params[] = $updates['title'];
        }
        
        if (empty($sets)) {
            return false;
        }
        
        $sets[] = 'updated_at = NOW()';
        $params[] = $uuid;
        
        $sql = "UPDATE {$this->prefix}documents SET " . implode(', ', $sets) . " WHERE uuid = ? AND deleted_at IS NULL";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Soft delete a document
     */
    public function deleteDocument(string $uuid): bool
    {
        $stmt = $this->db->prepare("
            UPDATE {$this->prefix}documents 
            SET deleted_at = NOW() 
            WHERE uuid = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$uuid]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Duplicate a document
     */
    public function duplicateDocument(string $uuid, string $newOwnerEmail): ?array
    {
        $newOwnerEmail = strtolower($newOwnerEmail);
        
        // Get original document
        $stmt = $this->db->prepare("
            SELECT id, title, type, crdt_state 
            FROM {$this->prefix}documents 
            WHERE uuid = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$uuid]);
        $original = $stmt->fetch();
        
        if (!$original) {
            return null;
        }
        
        $newUuid = $this->generateUuid();
        $newTitle = $original['title'] . ' (Copy)';
        
        $this->db->beginTransaction();
        
        try {
            // Create new document with same CRDT state
            $stmt = $this->db->prepare("
                INSERT INTO {$this->prefix}documents (uuid, owner_email, title, type, crdt_state)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$newUuid, $newOwnerEmail, $newTitle, $original['type'], $original['crdt_state']]);
            
            $documentId = (int)$this->db->lastInsertId();
            
            // Add owner permission
            $stmt = $this->db->prepare("
                INSERT INTO {$this->prefix}permissions (document_id, user_email, role)
                VALUES (?, ?, 'owner')
            ");
            $stmt->execute([$documentId, $newOwnerEmail]);
            
            $this->db->commit();
            
            return [
                'uuid' => $newUuid,
                'title' => $newTitle,
                'type' => $original['type'],
                'owner_email' => $newOwnerEmail,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'collaborator_count' => 1,
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * List versions for a document
     */
    public function listVersions(string $uuid, int $limit = 20, int $offset = 0): array
    {
        $limit = (int)$limit;
        $offset = (int)$offset;
        
        $stmt = $this->db->prepare("
            SELECT v.version_number, v.version_name, v.created_by, v.created_at
            FROM {$this->prefix}versions v
            JOIN {$this->prefix}documents d ON v.document_id = d.id
            WHERE d.uuid = ?
            ORDER BY v.version_number DESC
            LIMIT $limit OFFSET $offset
        ");
        $stmt->execute([$uuid]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Create a version snapshot
     */
    public function createVersion(string $uuid, string $createdBy, ?string $name = null): ?array
    {
        $createdBy = strtolower($createdBy);
        
        // Get document and current CRDT state
        $stmt = $this->db->prepare("
            SELECT id, crdt_state 
            FROM {$this->prefix}documents 
            WHERE uuid = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$uuid]);
        $doc = $stmt->fetch();
        
        if (!$doc || !$doc['crdt_state']) {
            return null;
        }
        
        // Get next version number
        $stmt = $this->db->prepare("
            SELECT COALESCE(MAX(version_number), 0) + 1 as next_version
            FROM {$this->prefix}versions
            WHERE document_id = ?
        ");
        $stmt->execute([$doc['id']]);
        $nextVersion = (int)$stmt->fetchColumn();
        
        // Insert version
        $stmt = $this->db->prepare("
            INSERT INTO {$this->prefix}versions (document_id, version_number, version_name, crdt_state, created_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$doc['id'], $nextVersion, $name, $doc['crdt_state'], $createdBy]);
        
        return [
            'version_number' => $nextVersion,
            'version_name' => $name,
            'created_by' => $createdBy,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    }
    
    /**
     * Restore document to a specific version
     */
    public function restoreVersion(string $uuid, int $versionNumber): bool
    {
        // Get version CRDT state
        $stmt = $this->db->prepare("
            SELECT v.crdt_state
            FROM {$this->prefix}versions v
            JOIN {$this->prefix}documents d ON v.document_id = d.id
            WHERE d.uuid = ? AND v.version_number = ?
        ");
        $stmt->execute([$uuid, $versionNumber]);
        $version = $stmt->fetch();
        
        if (!$version || !$version['crdt_state']) {
            return false;
        }
        
        // Update document with version's CRDT state
        $stmt = $this->db->prepare("
            UPDATE {$this->prefix}documents 
            SET crdt_state = ?, updated_at = NOW() 
            WHERE uuid = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$version['crdt_state'], $uuid]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Create a document from a Drive file
     * Imports the content and links to the source file
     * 
     * @param string $ownerEmail Owner's email
     * @param string $title Document title
     * @param string $type Document type (document or presentation)
     * @param int $driveFileId Linked Drive file ID
     * @param string|null $initialContent Initial HTML content for documents
     * @param array|null $initialSlides Initial slides data for presentations
     */
    public function createFromDriveFile(string $ownerEmail, string $title, string $type, int $driveFileId, ?string $initialContent = null, ?array $initialSlides = null): array
    {
        $ownerEmail = strtolower($ownerEmail);
        $uuid = $this->generateUuid();
        
        $this->db->beginTransaction();
        
        try {
            // Create document with drive_file_id link
            $stmt = $this->db->prepare("
                INSERT INTO {$this->prefix}documents (uuid, owner_email, title, type, drive_file_id)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$uuid, $ownerEmail, $title, $type, $driveFileId]);
            
            $documentId = (int)$this->db->lastInsertId();
            
            // Add owner permission
            $stmt = $this->db->prepare("
                INSERT INTO {$this->prefix}permissions (document_id, user_email, role)
                VALUES (?, ?, 'owner')
            ");
            $stmt->execute([$documentId, $ownerEmail]);
            
            $this->db->commit();
            
            return [
                'uuid' => $uuid,
                'title' => $title,
                'type' => $type,
                'owner_email' => $ownerEmail,
                'drive_file_id' => $driveFileId,
                'initial_content' => $initialContent,
                'initial_slides' => $initialSlides,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'collaborator_count' => 1,
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get document by UUID including drive_file_id
     */
    public function getDocumentWithDriveLink(string $uuid): ?array
    {
        $stmt = $this->db->prepare("
            SELECT uuid, title, type, owner_email, drive_file_id, created_at, updated_at,
                   (SELECT COUNT(*) FROM {$this->prefix}permissions WHERE document_id = d.id) as collaborator_count
            FROM {$this->prefix}documents d
            WHERE uuid = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$uuid]);
        
        $doc = $stmt->fetch();
        return $doc ?: null;
    }
    
    /**
     * Check if a collab document already exists for a drive file
     */
    public function findByDriveFileId(int $driveFileId, string $userEmail): ?array
    {
        $userEmail = strtolower($userEmail);
        
        $stmt = $this->db->prepare("
            SELECT d.uuid, d.title, d.type, d.owner_email, d.drive_file_id, d.created_at, d.updated_at
            FROM {$this->prefix}documents d
            LEFT JOIN {$this->prefix}permissions p ON d.id = p.document_id
            WHERE d.drive_file_id = ? 
              AND d.deleted_at IS NULL
              AND (d.owner_email = ? OR p.user_email = ?)
            LIMIT 1
        ");
        $stmt->execute([$driveFileId, $userEmail, $userEmail]);
        
        $doc = $stmt->fetch();
        return $doc ?: null;
    }
    
    /**
     * Generate UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}

