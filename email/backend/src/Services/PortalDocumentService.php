<?php

namespace Webmail\Services;

/**
 * PortalDocumentService - Document management, signing workflow, and audit trail
 * 
 * Handles the full document lifecycle:
 * - Create documents with file upload and signer assignment
 * - Track document status through draft → sent → viewed → signing → signed/rejected workflow
 * - Upload signed copies or use signature pad
 * - Sequential and parallel signing order support
 * - Full audit trail for every document action
 * - Reminder tracking for pending signers
 * 
 * Data isolation: portal users only see documents for their own client_id.
 * Internal users see all documents for clients they manage.
 */
class PortalDocumentService
{
    private \PDO $db;
    private array $config;

    // Valid document statuses in workflow order
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_VIEWED = 'viewed';
    public const STATUS_SIGNING = 'signing';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_ARCHIVED = 'archived';

    // Document types
    public const TYPES = ['contract', 'invoice', 'proposal', 'quote', 'nda', 'agreement', 'receipt', 'other'];

    // Signing methods
    public const METHOD_UPLOAD = 'upload';
    public const METHOD_PAD = 'pad';
    public const METHOD_BOTH = 'both';

    // Audit actions
    public const AUDIT_CREATED = 'created';
    public const AUDIT_SENT = 'sent';
    public const AUDIT_VIEWED = 'viewed';
    public const AUDIT_DOWNLOADED = 'downloaded';
    public const AUDIT_SIGNED = 'signed';
    public const AUDIT_REJECTED = 'rejected';
    public const AUDIT_UPLOADED = 'uploaded';
    public const AUDIT_REMINDER_SENT = 'reminder_sent';
    public const AUDIT_EXPIRED = 'expired';
    public const AUDIT_ARCHIVED = 'archived';
    public const AUDIT_VERSION_CREATED = 'version_created';

    public function __construct(array $config)
    {
        $this->config = $config;

        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    // =========================================================================
    // Documents CRUD
    // =========================================================================

    /**
     * Create a new document record.
     */
    public function createDocument(int $clientId, string $createdBy, array $data): array
    {
        $stmt = $this->db->prepare('
            INSERT INTO portal_documents 
                (client_id, created_by, title, description, document_type,
                 filename, original_name, mime_type, file_size, file_path,
                 signing_method, requires_all_signers, signing_deadline,
                 amount, currency, reference_number)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $clientId, $createdBy,
            $data['title'], $data['description'] ?? null, $data['document_type'] ?? 'other',
            $data['filename'], $data['original_name'], $data['mime_type'] ?? null,
            $data['file_size'] ?? 0, $data['file_path'],
            $data['signing_method'] ?? self::METHOD_BOTH,
            ($data['requires_all_signers'] ?? true) ? 1 : 0,
            $data['signing_deadline'] ?? null,
            $data['amount'] ?? null, $data['currency'] ?? 'HUF',
            $data['reference_number'] ?? null
        ]);

        return $this->getDocumentById((int)$this->db->lastInsertId());
    }

    /**
     * Get a document by ID with signers.
     */
    public function getDocumentById(int $docId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM portal_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if (!$doc) return null;

        $doc['signers'] = $this->getSigners($docId);
        return $doc;
    }

    /**
     * Get a document for portal user (validates client ownership + adds signer context).
     */
    public function getDocumentForPortal(int $docId, int $clientId, int $portalAccessId, string $portalEmail): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM portal_documents WHERE id = ? AND client_id = ?');
        $stmt->execute([$docId, $clientId]);
        $doc = $stmt->fetch();
        if (!$doc) return null;

        $doc['signers'] = $this->getSigners($docId);

        // Find this portal user's signer record
        $mySigner = null;
        foreach ($doc['signers'] as $s) {
            if ((int)$s['portal_access_id'] === $portalAccessId || strtolower($s['signer_email']) === strtolower($portalEmail)) {
                $mySigner = $s;
                break;
            }
        }
        $doc['my_signer'] = $mySigner;
        $doc['my_signer_status'] = $mySigner ? $mySigner['status'] : null;

        return $doc;
    }

    /**
     * List documents for a client (internal view).
     */
    public function listDocuments(int $clientId, ?string $statusFilter = null): array
    {
        $sql = '
            SELECT pd.*,
                   (SELECT COUNT(*) FROM portal_document_signers pds WHERE pds.document_id = pd.id) as signer_count,
                   (SELECT COUNT(*) FROM portal_document_signers pds WHERE pds.document_id = pd.id AND pds.status = ?) as signed_count,
                   (SELECT COUNT(*) FROM portal_document_signers pds WHERE pds.document_id = pd.id AND pds.status = ?) as pending_count
            FROM portal_documents pd
            WHERE pd.client_id = ?
        ';
        $params = ['signed', 'pending', $clientId];

        if ($statusFilter) {
            $sql .= ' AND pd.status = ?';
            $params[] = $statusFilter;
        }

        $sql .= ' ORDER BY pd.created_at DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * List documents visible to a portal user.
     */
    public function listDocumentsForPortal(int $clientId, int $portalAccessId, string $portalEmail): array
    {
        $stmt = $this->db->prepare("
            SELECT pd.*, 
                   pds.status as my_signer_status,
                   pds.signed_at as my_signed_at
            FROM portal_documents pd
            LEFT JOIN portal_document_signers pds ON pds.document_id = pd.id 
                AND (pds.portal_access_id = ? OR pds.signer_email = ?)
            WHERE pd.client_id = ? AND pd.status != 'draft'
            ORDER BY pd.created_at DESC
        ");
        $stmt->execute([$portalAccessId, strtolower($portalEmail), $clientId]);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // Signing Workflow
    // =========================================================================

    /**
     * Add signers to a document.
     */
    public function addSigners(int $docId, int $clientId, array $signers): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO portal_document_signers (document_id, portal_access_id, signer_email, signer_name, sign_order)
            VALUES (?, ?, ?, ?, ?)
        ');

        foreach ($signers as $idx => $signer) {
            // Try to find portal access for this email
            $accessStmt = $this->db->prepare('SELECT id FROM portal_access WHERE client_id = ? AND email = ? AND is_active = 1');
            $accessStmt->execute([$clientId, strtolower($signer['email'])]);
            $accessRow = $accessStmt->fetch();

            $stmt->execute([
                $docId,
                $accessRow ? $accessRow['id'] : null,
                strtolower($signer['email']),
                $signer['name'] ?? null,
                $signer['sign_order'] ?? $idx
            ]);
        }
    }

    /**
     * Get signers for a document.
     */
    public function getSigners(int $docId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM portal_document_signers WHERE document_id = ? ORDER BY sign_order ASC, id ASC');
        $stmt->execute([$docId]);
        return $stmt->fetchAll();
    }

    /**
     * Record a signed document upload.
     */
    public function recordUploadSignature(int $signerId, string $filePath, string $filename, string $ip, string $userAgent): bool
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                UPDATE portal_document_signers 
                SET status = 'signed', signed_at = NOW(), signature_type = 'upload',
                    uploaded_file_path = ?, uploaded_filename = ?,
                    signature_ip = ?, signature_user_agent = ?
                WHERE id = ?
            ")->execute([$filePath, $filename, $ip, $userAgent, $signerId]);

            $signer = $this->getSignerById($signerId);
            if ($signer) {
                $this->checkDocumentCompletion($signer['document_id']);
            }

            $this->db->commit();
            return true;
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("PortalDocumentService: Upload signature error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record a pad signature.
     */
    public function recordPadSignature(int $signerId, string $signatureData, string $ip, string $userAgent): bool
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare("
                UPDATE portal_document_signers 
                SET status = 'signed', signed_at = NOW(), signature_type = 'pad',
                    signature_data = ?,
                    signature_ip = ?, signature_user_agent = ?
                WHERE id = ?
            ")->execute([$signatureData, $ip, $userAgent, $signerId]);

            $signer = $this->getSignerById($signerId);
            if ($signer) {
                $this->checkDocumentCompletion($signer['document_id']);
            }

            $this->db->commit();
            return true;
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("PortalDocumentService: Pad signature error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Record a document rejection.
     */
    public function rejectSigning(int $signerId, ?string $reason): bool
    {
        $this->db->prepare("
            UPDATE portal_document_signers SET status = 'rejected', rejection_reason = ? WHERE id = ?
        ")->execute([$reason, $signerId]);

        $signer = $this->getSignerById($signerId);
        if ($signer) {
            $this->db->prepare("UPDATE portal_documents SET status = 'rejected' WHERE id = ?")
                ->execute([$signer['document_id']]);
        }

        return true;
    }

    /**
     * Get a signer by ID.
     */
    public function getSignerById(int $signerId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM portal_document_signers WHERE id = ?');
        $stmt->execute([$signerId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Check signing order - returns error message or null if OK.
     */
    public function checkSigningOrder(int $docId, int $currentOrder): ?string
    {
        if ($currentOrder === 0) return null; // Parallel signing

        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM portal_document_signers 
            WHERE document_id = ? AND sign_order > 0 AND sign_order < ? AND status != 'signed'
        ");
        $stmt->execute([$docId, $currentOrder]);

        if ((int)$stmt->fetchColumn() > 0) {
            return 'Waiting for previous signers to complete.';
        }

        return null;
    }

    /**
     * Check if all signers have signed and update document status accordingly.
     */
    private function checkDocumentCompletion(int $docId): void
    {
        $stmt = $this->db->prepare('SELECT requires_all_signers FROM portal_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if (!$doc) return;

        if ($doc['requires_all_signers']) {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM portal_document_signers WHERE document_id = ? AND status != 'signed'");
            $stmt->execute([$docId]);
            if ((int)$stmt->fetchColumn() === 0) {
                $this->db->prepare("UPDATE portal_documents SET status = 'signed', completed_at = NOW() WHERE id = ?")
                    ->execute([$docId]);
            } else {
                $this->db->prepare("UPDATE portal_documents SET status = 'signing' WHERE id = ? AND status NOT IN ('signed', 'rejected')")
                    ->execute([$docId]);
            }
        } else {
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM portal_document_signers WHERE document_id = ? AND status = 'signed'");
            $stmt->execute([$docId]);
            if ((int)$stmt->fetchColumn() > 0) {
                $this->db->prepare("UPDATE portal_documents SET status = 'signed', completed_at = NOW() WHERE id = ?")
                    ->execute([$docId]);
            }
        }
    }

    // =========================================================================
    // Audit Trail
    // =========================================================================

    /**
     * Log a document action to the audit trail.
     */
    public function logAudit(int $docId, string $action, string $actorType, ?string $actorEmail, ?string $ip = null, ?string $userAgent = null, ?array $details = null): void
    {
        try {
            $this->db->prepare('
                INSERT INTO portal_document_audit (document_id, action, actor_type, actor_email, ip_address, user_agent, details)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $docId, $action, $actorType, $actorEmail, $ip, $userAgent,
                $details ? json_encode($details) : null
            ]);
        } catch (\Throwable $e) {
            error_log("PortalDocumentService: Audit log error: " . $e->getMessage());
        }
    }

    /**
     * Get audit trail for a document.
     */
    public function getAuditTrail(int $docId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM portal_document_audit WHERE document_id = ? ORDER BY created_at DESC');
        $stmt->execute([$docId]);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // Document Status Management
    // =========================================================================

    /**
     * Update document status (e.g., send for signing).
     */
    public function updateStatus(int $docId, string $newStatus): bool
    {
        $stmt = $this->db->prepare('UPDATE portal_documents SET status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$newStatus, $docId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark document as viewed by portal user.
     */
    public function markViewed(int $docId): void
    {
        $this->db->prepare("
            UPDATE portal_documents SET viewed_at = COALESCE(viewed_at, NOW()), 
                   status = CASE WHEN status = 'sent' THEN 'viewed' ELSE status END
            WHERE id = ?
        ")->execute([$docId]);
    }

    /**
     * Get the storage base path (with fallback).
     */
    private function getBaseStoragePath(): string
    {
        return $this->config['storage_path']
            ?? dirname(__DIR__, 2) . '/storage';
    }

    /**
     * Get storage path for document files.
     */
    public function getStoragePath(int $clientId): string
    {
        $path = $this->getBaseStoragePath() . '/portal/' . $clientId . '/documents/';
        if (NasHealthCheck::shouldSkipPath($path)) {
            $path = dirname(__DIR__, 2) . '/storage/portal/' . $clientId . '/documents/';
        }
        if (!is_dir($path)) mkdir($path, 0755, true);
        return $path;
    }

    /**
     * Get storage path for signed document uploads.
     */
    public function getSignedStoragePath(int $clientId): string
    {
        $path = $this->getBaseStoragePath() . '/portal/' . $clientId . '/signed/';
        if (NasHealthCheck::shouldSkipPath($path)) {
            $path = dirname(__DIR__, 2) . '/storage/portal/' . $clientId . '/signed/';
        }
        if (!is_dir($path)) mkdir($path, 0755, true);
        return $path;
    }
}

