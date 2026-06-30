<?php

namespace Webmail\Addons\CrmPro\Controllers;

use Webmail\Controllers\BaseController;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\CrmPro\Services\PortalService;
use Webmail\Services\MeetingRoomService;

/**
 * PortalController - Handles all Client Portal endpoints
 * 
 * Two types of endpoints:
 * 1. Portal-facing: Authenticated via X-Portal-Token header (portal sessions)
 * 2. Internal (CRM): Authenticated via JWT Bearer token (internal users managing portal)
 * 
 * Portal auth is completely independent from the internal JWT system.
 */
class PortalController extends BaseController
{
    private PortalService $portalService;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->portalService = new PortalService($config);
    }

    // =========================================================================
    // Portal Auth Endpoints (public / portal-authenticated)
    // =========================================================================

    /**
     * Consume magic link and create portal session
     * GET /portal/auth/{token}
     */
    public function auth(Request $request): Response
    {
        $token = $request->getParam('token');
        if (!$token) {
            return Response::badRequest('Token is required');
        }

        $result = $this->portalService->consumeMagicLink(
            $token,
            $request->getClientIp(),
            $request->getHeader('User-Agent')
        );

        if (isset($result['error'])) {
            $code = $result['code'] ?? 'error';
            if ($code === 'revoked') return Response::forbidden($result['error']);
            return Response::badRequest($result['error']);
        }

        return Response::success($result);
    }

    /**
     * Client requests a new magic link by email
     * POST /portal/request-link
     */
    public function requestLink(Request $request): Response
    {
        $email = $request->input('email');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::badRequest('Valid email is required');
        }

        $result = $this->portalService->requestMagicLink($email, $request->getClientIp());

        if (isset($result['error'])) {
            return Response::tooManyRequests($result['error']);
        }

        return Response::success($result);
    }

    /**
     * Get current portal user info
     * GET /portal/me
     */
    public function me(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $info = $this->portalService->getPortalUser($this->getPortalToken($request));
        if (!$info) {
            return Response::unauthorized('Invalid session');
        }

        return Response::success($info);
    }

    /**
     * End portal session
     * POST /portal/logout
     */
    public function logout(Request $request): Response
    {
        $token = $this->getPortalToken($request);
        if (!$token) {
            return Response::unauthorized('No session');
        }

        $this->portalService->endSession($token);
        return Response::success(null, 'Logged out');
    }

    // =========================================================================
    // Portal Updates (portal-authenticated)
    // =========================================================================

    /**
     * Get updates feed for portal client
     * GET /portal/updates
     */
    public function getUpdates(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $db = $this->portalService->getDb();
        $page = max(1, (int)$request->getQuery('page', 1));
        $perPage = min(50, max(10, (int)$request->getQuery('per_page', 20)));
        $offset = ($page - 1) * $perPage;

        // Get total count
        $countStmt = $db->prepare('SELECT COUNT(*) FROM portal_updates WHERE client_id = ?');
        $countStmt->execute([$portalUser['client_id']]);
        $total = (int)$countStmt->fetchColumn();

        // Get updates with read status
        $stmt = $db->prepare('
            SELECT pu.*, 
                   (SELECT COUNT(*) FROM portal_comments pc WHERE pc.update_id = pu.id) as comment_count,
                   (SELECT COUNT(*) FROM portal_update_files puf WHERE puf.update_id = pu.id) as file_count,
                   pur.read_at
            FROM portal_updates pu
            LEFT JOIN portal_update_reads pur ON pur.update_id = pu.id AND pur.portal_access_id = ?
            WHERE pu.client_id = ?
            ORDER BY pu.is_pinned DESC, pu.created_at DESC
            LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset . '
        ');
        $stmt->execute([$portalUser['portal_access_id'], $portalUser['client_id']]);
        $updates = $stmt->fetchAll();

        return Response::success([
            'updates' => $updates,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
        ]);
    }

    /**
     * Get single update with comments
     * GET /portal/updates/{id}
     */
    public function getUpdate(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $updateId = (int)$request->getParam('id');
        $db = $this->portalService->getDb();

        // Get the update
        $stmt = $db->prepare('SELECT * FROM portal_updates WHERE id = ? AND client_id = ?');
        $stmt->execute([$updateId, $portalUser['client_id']]);
        $update = $stmt->fetch();

        if (!$update) {
            return Response::notFound('Update not found');
        }

        // Get comments
        $stmt = $db->prepare('
            SELECT * FROM portal_comments 
            WHERE update_id = ? 
            ORDER BY created_at ASC
        ');
        $stmt->execute([$updateId]);
        $comments = $stmt->fetchAll();

        // Get files
        $stmt = $db->prepare('SELECT * FROM portal_update_files WHERE update_id = ?');
        $stmt->execute([$updateId]);
        $files = $stmt->fetchAll();

        $update['comments'] = $comments;
        $update['files'] = $files;

        return Response::success($update);
    }

    /**
     * Mark an update as read
     * POST /portal/updates/{id}/read
     */
    public function markUpdateRead(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $updateId = (int)$request->getParam('id');
        $db = $this->portalService->getDb();

        // Verify update belongs to this client
        $stmt = $db->prepare('SELECT id FROM portal_updates WHERE id = ? AND client_id = ?');
        $stmt->execute([$updateId, $portalUser['client_id']]);
        if (!$stmt->fetch()) {
            return Response::notFound('Update not found');
        }

        // Insert or ignore read record
        $stmt = $db->prepare('
            INSERT IGNORE INTO portal_update_reads (update_id, portal_access_id, read_at)
            VALUES (?, ?, NOW())
        ');
        $stmt->execute([$updateId, $portalUser['portal_access_id']]);

        return Response::success(null, 'Marked as read');
    }

    /**
     * Add comment to an update (portal user)
     * POST /portal/updates/{id}/comments
     */
    public function addComment(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $updateId = (int)$request->getParam('id');
        $content = trim($request->input('content') ?? '');
        $parentId = $request->input('parent_comment_id');

        if (empty($content)) {
            return Response::badRequest('Comment content is required');
        }

        $db = $this->portalService->getDb();

        // Verify update belongs to this client
        $stmt = $db->prepare('SELECT id FROM portal_updates WHERE id = ? AND client_id = ?');
        $stmt->execute([$updateId, $portalUser['client_id']]);
        if (!$stmt->fetch()) {
            return Response::notFound('Update not found');
        }

        $stmt = $db->prepare('
            INSERT INTO portal_comments (update_id, author_type, author_email, author_name, content_text, parent_comment_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $updateId, 'portal', $portalUser['email'], $portalUser['name'],
            $content, $parentId ? (int)$parentId : null
        ]);

        $commentId = (int)$db->lastInsertId();

        $stmt = $db->prepare('SELECT * FROM portal_comments WHERE id = ?');
        $stmt->execute([$commentId]);

        return Response::success($stmt->fetch(), 'Comment added');
    }

    /**
     * Download a file attached to an update
     * GET /portal/updates/{id}/files/{fileId}
     */
    public function downloadUpdateFile(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $updateId = (int)$request->getParam('id');
        $fileId = (int)$request->getParam('fileId');
        $db = $this->portalService->getDb();

        // Verify update belongs to this client and get file
        $stmt = $db->prepare('
            SELECT puf.* FROM portal_update_files puf
            JOIN portal_updates pu ON puf.update_id = pu.id
            WHERE puf.id = ? AND puf.update_id = ? AND pu.client_id = ?
        ');
        $stmt->execute([$fileId, $updateId, $portalUser['client_id']]);
        $file = $stmt->fetch();

        if (!$file) {
            return Response::notFound('File not found');
        }

        $relativePath = '/portal/' . $portalUser['client_id'] . '/updates/' . $file['filename'];
        $filePath = $this->resolvePortalFile($relativePath);
        if (!$filePath) {
            return Response::notFound('File not found on disk');
        }

        $mime = $file['mime_type'] ?? 'application/octet-stream';
        $inline = $request->getQuery('inline') === '1';
        $disposition = $inline ? 'inline' : 'attachment';

        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($file['original_name']) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=3600');

        // Support range requests for audio/video seeking
        if ($inline && isset($_SERVER['HTTP_RANGE'])) {
            $fileSize = filesize($filePath);
            $range = str_replace('bytes=', '', $_SERVER['HTTP_RANGE']);
            $parts = explode('-', $range);
            $start = (int)$parts[0];
            $end = isset($parts[1]) && $parts[1] !== '' ? (int)$parts[1] : $fileSize - 1;
            $length = $end - $start + 1;

            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes $start-$end/$fileSize");
            header("Content-Length: $length");

            $fp = fopen($filePath, 'rb');
            fseek($fp, $start);
            echo fread($fp, $length);
            fclose($fp);
        } else {
            readfile($filePath);
        }
        exit;
    }

    // =========================================================================
    // Portal Documents (portal-authenticated)
    // =========================================================================

    /**
     * Get documents for portal client
     * GET /portal/documents
     */
    public function getDocuments(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $db = $this->portalService->getDb();

        $stmt = $db->prepare('
            SELECT pd.id, pd.title, pd.description, pd.document_type, pd.status, pd.signing_method,
                   pd.signing_deadline, pd.amount, pd.currency, pd.reference_number, pd.version,
                   pd.original_name, pd.file_size, pd.mime_type, pd.created_at,
                   pds.status as my_signer_status, pds.signed_at as my_signed_at
            FROM portal_documents pd
            LEFT JOIN portal_document_signers pds ON pds.document_id = pd.id AND pds.portal_access_id = ?
            WHERE pd.client_id = ? AND pd.status NOT IN (?, ?)
            ORDER BY pd.created_at DESC
        ');
        $stmt->execute([$portalUser['portal_access_id'], $portalUser['client_id'], 'draft', 'archived']);

        return Response::success(['documents' => $stmt->fetchAll()]);
    }

    /**
     * Get single document details
     * GET /portal/documents/{docId}
     */
    public function getDocument(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('
            SELECT pd.*, pds.status as my_signer_status, pds.signed_at as my_signed_at, pds.id as my_signer_id
            FROM portal_documents pd
            LEFT JOIN portal_document_signers pds ON pds.document_id = pd.id AND pds.portal_access_id = ?
            WHERE pd.id = ? AND pd.client_id = ?
        ');
        $stmt->execute([$portalUser['portal_access_id'], $docId, $portalUser['client_id']]);
        $doc = $stmt->fetch();

        if (!$doc) {
            return Response::notFound('Document not found');
        }

        // Record view if first time
        if (!$doc['viewed_at']) {
            $db->prepare('UPDATE portal_documents SET viewed_at = NOW(), status = IF(status = ?, ?, status) WHERE id = ?')
                ->execute(['sent', 'viewed', $docId]);
        }

        // Log audit
        $this->logDocumentAudit($db, $docId, 'viewed', 'portal', $portalUser['email'], $request);

        // Get all signers (show names but not sensitive data)
        $stmt = $db->prepare('
            SELECT id, signer_email, signer_name, status, signed_at, sign_order
            FROM portal_document_signers WHERE document_id = ?
            ORDER BY sign_order ASC, id ASC
        ');
        $stmt->execute([$docId]);
        $doc['signers'] = $stmt->fetchAll();

        return Response::success($doc);
    }

    /**
     * Download original document file
     * GET /portal/documents/{docId}/download
     */
    public function downloadDocument(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('
            SELECT * FROM portal_documents WHERE id = ? AND client_id = ? AND status NOT IN (?, ?)
        ');
        $stmt->execute([$docId, $portalUser['client_id'], 'draft', 'archived']);
        $doc = $stmt->fetch();

        if (!$doc) {
            return Response::notFound('Document not found');
        }

        // Try stored path first, then resolve across possible locations
        $filePath = $doc['file_path'];
        if (!file_exists($filePath)) {
            // Try resolving with the relative portal path
            $relativePath = '/portal/' . $portalUser['client_id'] . '/documents/' . $doc['filename'];
            $filePath = $this->resolvePortalFile($relativePath);
            if (!$filePath) {
                return Response::notFound('File not found on disk');
            }
        }

        // Log download
        $this->logDocumentAudit($db, $docId, 'downloaded', 'portal', $portalUser['email'], $request);

        header('Content-Type: ' . ($doc['mime_type'] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . addslashes($doc['original_name']) . '"');
        header('Content-Length: ' . filesize($filePath));
        readfile($filePath);
        exit;
    }

    /**
     * Upload signed document copy
     * POST /portal/documents/{docId}/sign/upload
     */
    public function signUpload(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        // Validate signer
        $signer = $this->getMySignerRecord($db, $docId, $portalUser);
        if (!$signer) return Response::notFound('Document not found or you are not a signer');
        if ($signer['status'] === 'signed') return Response::badRequest('You have already signed this document');

        // Check sequential signing order
        if ($signer['sign_order'] > 0) {
            $blockCheck = $this->checkSigningOrder($db, $docId, $signer['sign_order']);
            if ($blockCheck) return Response::badRequest($blockCheck);
        }

        // Handle file upload
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return Response::badRequest('File upload is required');
        }

        $file = $_FILES['file'];
        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($detectedMime, $allowedMimes)) {
            return Response::badRequest('Invalid file type. Allowed: PDF, JPEG, PNG, DOC, DOCX');
        }

        if ($file['size'] > 20 * 1024 * 1024) {
            return Response::badRequest('File too large (max 20MB)');
        }

        // Store the signed file
        $storagePath = $this->getPortalStoragePath() . '/portal/' . $portalUser['client_id'] . '/documents/signed/';
        if (!is_dir($storagePath)) mkdir($storagePath, 0755, true);

        $filename = 'signed_' . $docId . '_' . $signer['id'] . '_' . time() . '.' . pathinfo($file['name'], PATHINFO_EXTENSION);
        move_uploaded_file($file['tmp_name'], $storagePath . $filename);

        // Update signer record
        $stmt = $db->prepare('
            UPDATE portal_document_signers 
            SET status = ?, signed_at = NOW(), signature_type = ?, 
                uploaded_file_path = ?, uploaded_filename = ?,
                signature_ip = ?, signature_user_agent = ?
            WHERE id = ?
        ');
        $stmt->execute([
            'signed', 'upload', $storagePath . $filename, $file['name'],
            $request->getClientIp(), $request->getHeader('User-Agent'), $signer['id']
        ]);

        // Handle optional stamp upload
        $stampData = $request->input('stamp_data');
        if ($stampData) {
            $db->prepare('UPDATE portal_document_signers SET stamp_data = ? WHERE id = ?')
                ->execute([$stampData, $signer['id']]);
        }

        // Log audit
        $this->logDocumentAudit($db, $docId, 'signed', 'portal', $portalUser['email'], $request, [
            'method' => 'upload', 'filename' => $file['name']
        ]);

        // Check if all signers have signed
        $this->checkDocumentCompletion($db, $docId);

        return Response::success(null, 'Document signed successfully');
    }

    /**
     * Submit signature pad drawing
     * POST /portal/documents/{docId}/sign/pad
     */
    public function signPad(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $docId = (int)$request->getParam('docId');
        $signatureData = $request->input('signature_data'); // base64 PNG (drawn or uploaded)
        $stampData = $request->input('stamp_data'); // base64 PNG (company stamp)

        $db = $this->portalService->getDb();

        // Validate signer
        $signer = $this->getMySignerRecord($db, $docId, $portalUser);
        if (!$signer) return Response::notFound('Document not found or you are not a signer');
        if ($signer['status'] === 'signed') return Response::badRequest('You have already signed this document');

        // Check sequential signing order
        if ($signer['sign_order'] > 0) {
            $blockCheck = $this->checkSigningOrder($db, $docId, $signer['sign_order']);
            if ($blockCheck) return Response::badRequest($blockCheck);
        }

        // Check zones to determine what's required (assigned + unassigned zones)
        try {
            $zoneStmt = $db->prepare('
                SELECT zone_type FROM portal_document_zones 
                WHERE document_id = ? AND (
                    signer_id = ? 
                    OR signer_email = ? 
                    OR (signer_id IS NULL AND (signer_email IS NULL OR signer_email = \'\'))
                )
            ');
            $zoneStmt->execute([$docId, $signer['id'], strtolower($portalUser['email'])]);
            $zoneTypes = $zoneStmt->fetchAll(\PDO::FETCH_COLUMN);

            $needsSignature = in_array('signature', $zoneTypes) || in_array('signature_and_stamp', $zoneTypes);
            $needsStamp = in_array('stamp', $zoneTypes) || in_array('signature_and_stamp', $zoneTypes);

            if ($needsSignature && empty($signatureData)) {
                return Response::badRequest('Signature is required for this document');
            }
            if ($needsStamp && empty($stampData)) {
                return Response::badRequest('Company stamp is required for this document');
            }
        } catch (\Throwable $e) {
            error_log("signPad zone check: " . $e->getMessage());
            if (empty($signatureData)) {
                return Response::badRequest('Signature data is required');
            }
        }

        // Update signer record (now including stamp_data)
        $stmt = $db->prepare('
            UPDATE portal_document_signers 
            SET status = ?, signed_at = NOW(), signature_type = ?, signature_data = ?,
                stamp_data = ?, signature_ip = ?, signature_user_agent = ?
            WHERE id = ?
        ');
        $stmt->execute([
            'signed', 'pad', $signatureData, $stampData,
            $request->getClientIp(), $request->getHeader('User-Agent'), $signer['id']
        ]);

        // Log audit
        $this->logDocumentAudit($db, $docId, 'signed', 'portal', $portalUser['email'], $request, [
            'method' => 'pad',
            'has_stamp' => !empty($stampData),
        ]);

        // Generate signed PDF if zones exist
        $this->generateSignedPdf($db, $docId, $signer, $portalUser, $signatureData, $stampData, $request);

        // Check if all signers have signed
        $this->checkDocumentCompletion($db, $docId);

        return Response::success(null, 'Document signed successfully');
    }

    /**
     * Reject a document with reason
     * POST /portal/documents/{docId}/reject
     */
    public function rejectDocument(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $docId = (int)$request->getParam('docId');
        $reason = trim($request->input('reason') ?? '');
        $db = $this->portalService->getDb();

        // Validate signer
        $signer = $this->getMySignerRecord($db, $docId, $portalUser);
        if (!$signer) return Response::notFound('Document not found or you are not a signer');
        if ($signer['status'] === 'signed') return Response::badRequest('Cannot reject: already signed');

        // Update signer
        $stmt = $db->prepare('
            UPDATE portal_document_signers SET status = ?, rejection_reason = ? WHERE id = ?
        ');
        $stmt->execute(['rejected', $reason, $signer['id']]);

        // Update document status
        $db->prepare('UPDATE portal_documents SET status = ? WHERE id = ?')->execute(['rejected', $docId]);

        // Log audit
        $this->logDocumentAudit($db, $docId, 'rejected', 'portal', $portalUser['email'], $request, ['reason' => $reason]);

        return Response::success(null, 'Document rejected');
    }

    // =========================================================================
    // Portal Calls (portal-authenticated)
    // =========================================================================

    /**
     * Get scheduled/active calls
     * GET /portal/calls
     */
    public function getCalls(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $db = $this->portalService->getDb();
        $stmt = $db->prepare('
            SELECT * FROM portal_calls 
            WHERE client_id = ? AND status IN (?, ?)
            ORDER BY FIELD(status, ?, ?) ASC, created_at DESC
        ');
        $stmt->execute([$portalUser['client_id'], 'waiting', 'active', 'active', 'waiting']);

        return Response::success(['calls' => $stmt->fetchAll()]);
    }

    /**
     * Join a portal call (get LiveKit guest token)
     * POST /portal/calls/{callId}/join
     */
    public function joinCall(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $callId = (int)$request->getParam('callId');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('SELECT * FROM portal_calls WHERE id = ? AND client_id = ? AND status IN (?, ?)');
        $stmt->execute([$callId, $portalUser['client_id'], 'waiting', 'active']);
        $call = $stmt->fetch();

        if (!$call) {
            return Response::notFound('Call not found or not available');
        }

        // Generate LiveKit guest token using existing CallService
        try {
            $callService = new \Webmail\Services\CallService($this->config);
            $tokenData = $callService->getLiveKitToken(
                $call['room_name'],
                $portalUser['email'],
                $portalUser['name'] ?? $portalUser['email']
            );

            // Update call status if waiting
            if ($call['status'] === 'waiting') {
                $db->prepare('UPDATE portal_calls SET status = ?, started_at = NOW() WHERE id = ? AND status = ?')
                    ->execute(['active', $callId, 'waiting']);
            }

            $this->addCallParticipant($db, $callId, 'portal', $portalUser['email'], $portalUser['name'] ?? null);

            return Response::success([
                'token' => $tokenData['token'],
                'room_name' => $call['room_name'],
                'livekit_url' => $tokenData['ws_url'] ?? $this->config['livekit']['ws_url'] ?? '',
                'participant_name' => $portalUser['name'] ?? $portalUser['email'],
            ]);
        } catch (\Throwable $e) {
            error_log("PortalController: Failed to generate LiveKit token: " . $e->getMessage());
            return Response::serverError('Failed to join call');
        }
    }

    /**
     * End a portal call (portal client side)
     * POST /portal/calls/{callId}/end
     */
    public function endPortalCall(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $callId = (int)$request->getParam('callId');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('SELECT * FROM portal_calls WHERE id = ? AND client_id = ?');
        $stmt->execute([$callId, $portalUser['client_id']]);
        $call = $stmt->fetch();

        if (!$call) {
            return Response::notFound('Call not found');
        }

        if ($call['status'] === 'active' || $call['status'] === 'waiting') {
            $db->prepare('UPDATE portal_calls SET status = ?, ended_at = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, COALESCE(started_at, created_at), NOW()) WHERE id = ?')
                ->execute(['ended', $callId]);

            $this->trackCallTime($db, $call);
            $this->bridgeCallToWorkSession($db, $call);
            $this->finalizeCallParticipants($db, $callId);
        }

        return Response::success(null, 'Call ended');
    }

    // =========================================================================
    // Internal CRM Endpoints (JWT-authenticated)
    // =========================================================================

    /**
     * Grant portal access to a contact
     * POST /clients/{id}/portal/grant
     * 
     * Automatically generates a magic link and attempts to send it via email.
     * Returns the portal link URL in the response so the admin can copy/share it manually.
     */
    public function grantAccess(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $email = $request->input('email');
        $name = $request->input('name');
        $contactId = $request->input('contact_id');

        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::badRequest('Valid email is required');
        }

        $result = $this->portalService->grantAccess($clientId, $email, $name, $this->userEmail, $contactId ? (int)$contactId : null);

        if (isset($result['error'])) {
            return Response::badRequest($result['error']);
        }

        // Auto-generate a magic link after granting access
        $accessId = $result['id'] ?? null;
        $linkData = null;
        $emailSent = false;

        if ($accessId) {
            // Generate magic link
            $linkData = $this->portalService->generateMagicLink($accessId);

            // Try to send it via email (non-blocking — if it fails, admin can copy the link)
            try {
                $emailResult = $this->portalService->sendMagicLinkEmail($clientId, $accessId, $this->userEmail);
                $emailSent = !isset($emailResult['error']);
            } catch (\Throwable $e) {
                error_log("PortalController: Auto-send magic link failed: " . $e->getMessage());
                $emailSent = false;
            }
        }

        $responseData = $result;
        if ($linkData && !isset($linkData['error'])) {
            $responseData['portal_link'] = $linkData['link'];
            $responseData['link_expires_at'] = $linkData['expires_at'];
        }
        $responseData['email_sent'] = $emailSent;

        $message = $emailSent
            ? 'Portal access granted and magic link sent to ' . $email
            : 'Portal access granted. Magic link generated — copy and share it with the client.';

        return Response::success($responseData, $message);
    }

    /**
     * Revoke portal access
     * DELETE /clients/{id}/portal/revoke/{accessId}
     */
    public function revokeAccess(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $accessId = (int)$request->getParam('accessId');
        $this->portalService->revokeAccess($accessId);

        return Response::success(null, 'Portal access revoked');
    }

    /**
     * List portal access entries for a client
     * GET /clients/{id}/portal/access
     */
    public function listAccess(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $access = $this->portalService->listAccess($clientId);

        return Response::success(['access' => $access]);
    }

    /**
     * Send magic link email to a portal contact
     * POST /clients/{id}/portal/send-link
     */
    public function sendMagicLink(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        // Accept both parameter names for compatibility
        $accessId = (int)($request->input('portal_access_id') ?: $request->input('access_id'));

        if (!$accessId) {
            return Response::badRequest('Portal access ID is required');
        }

        $result = $this->portalService->sendMagicLinkEmail($clientId, $accessId, $this->userEmail);

        if (isset($result['error'])) {
            return Response::badRequest($result['error']);
        }

        return Response::success($result, 'Magic link sent');
    }

    /**
     * Generate a magic link without sending email (for manual sharing)
     * POST /clients/{id}/portal/generate-link
     * 
     * Returns the portal link URL so the admin can copy and share it
     * via WhatsApp, Slack, or any other channel.
     */
    public function generateLink(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $accessId = (int)($request->input('portal_access_id') ?: $request->input('access_id'));

        if (!$accessId) {
            return Response::badRequest('Portal access ID is required');
        }

        $result = $this->portalService->generateMagicLink($accessId);

        if (isset($result['error'])) {
            return Response::badRequest($result['error']);
        }

        return Response::success([
            'portal_link' => $result['link'],
            'expires_at' => $result['expires_at'],
        ], 'Magic link generated. Copy and share with the client.');
    }

    // =========================================================================
    // Internal CRM: Updates Management
    // =========================================================================

    /**
     * Create a new update for a client
     * POST /clients/{id}/portal/updates
     */
    public function createUpdate(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $title = trim($request->input('title') ?? '');
        $contentHtml = $request->input('content_html');
        $contentText = $request->input('content_text');
        $updateType = $request->input('update_type') ?? 'general';
        $moodBoardId = $request->input('mood_board_id');
        $driveFileIds = $request->input('drive_file_ids');
        $boardId = $request->input('board_id');
        $boardCardId = $request->input('board_card_id');

        if (empty($title)) {
            return Response::badRequest('Title is required');
        }

        $db = $this->portalService->getDb();

        // Auto-generate mood board share token if linking a mood board
        $moodBoardShareToken = null;
        if ($moodBoardId) {
            try {
                $moodService = new \Webmail\Addons\Moodboards\Services\MoodBoardService($this->config, $this->userEmail);
                $shareResult = $moodService->createShareLink($moodBoardId);
                $moodBoardShareToken = $shareResult['share_token'] ?? null;
            } catch (\Throwable $e) {
                error_log("PortalController: Failed to create mood board share link: " . $e->getMessage());
            }
        }

        $stmt = $db->prepare('
            INSERT INTO portal_updates (client_id, created_by, title, content_html, content_text, update_type,
                                       mood_board_id, mood_board_share_token, drive_file_ids, board_id, board_card_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $clientId, $this->userEmail, $title, $contentHtml, $contentText, $updateType,
            $moodBoardId, $moodBoardShareToken, $driveFileIds ? json_encode($driveFileIds) : null,
            $boardId, $boardCardId
        ]);

        $updateId = (int)$db->lastInsertId();

        // Fetch created update
        $stmt = $db->prepare('SELECT * FROM portal_updates WHERE id = ?');
        $stmt->execute([$updateId]);

        return Response::success($stmt->fetch(), 'Update created');
    }

    /**
     * List updates for a client (internal view)
     * GET /clients/{id}/portal/updates
     */
    public function listClientUpdates(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('
            SELECT pu.*,
                   (SELECT COUNT(*) FROM portal_comments pc WHERE pc.update_id = pu.id) as comment_count,
                   (SELECT COUNT(*) FROM portal_update_files puf WHERE puf.update_id = pu.id) as file_count,
                   (SELECT COUNT(*) FROM portal_update_reads pur WHERE pur.update_id = pu.id) as read_count,
                   (SELECT COUNT(*) FROM portal_access pa WHERE pa.client_id = pu.client_id AND pa.is_active = 1) as total_recipients
            FROM portal_updates pu
            WHERE pu.client_id = ?
            ORDER BY pu.created_at DESC
        ');
        $stmt->execute([$clientId]);

        return Response::success(['updates' => $stmt->fetchAll()]);
    }

    /**
     * Add internal comment to an update
     * POST /clients/{id}/portal/updates/{updateId}/comments
     */
    public function addInternalComment(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $updateId = (int)$request->getParam('updateId');
        $content = trim($request->input('content') ?? '');
        $parentId = $request->input('parent_comment_id');

        if (empty($content)) {
            return Response::badRequest('Comment content is required');
        }

        $db = $this->portalService->getDb();
        $stmt = $db->prepare('
            INSERT INTO portal_comments (update_id, author_type, author_email, author_name, content_text, parent_comment_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$updateId, 'internal', $this->userEmail, null, $content, $parentId ? (int)$parentId : null]);

        $commentId = (int)$db->lastInsertId();
        $stmt = $db->prepare('SELECT * FROM portal_comments WHERE id = ?');
        $stmt->execute([$commentId]);

        return Response::success($stmt->fetch(), 'Comment added');
    }

    /**
     * Attach file to an update
     * POST /clients/{id}/portal/updates/{updateId}/files
     */
    public function attachUpdateFile(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $updateId = (int)$request->getParam('updateId');

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['file']['error'] ?? 'no file';
            error_log("PortalController::attachUpdateFile: No file received or upload error. \$_FILES keys: " . implode(',', array_keys($_FILES)) . " error: $errorCode");
            return Response::badRequest('File upload is required (error: ' . $errorCode . ')');
        }

        $file = $_FILES['file'];
        if ($file['size'] > 50 * 1024 * 1024) {
            return Response::badRequest('File too large (max 50MB)');
        }

        try {
            $storagePath = $this->getPortalStoragePath() . '/portal/' . $clientId . '/updates/';
            if (!is_dir($storagePath)) {
                if (!mkdir($storagePath, 0755, true)) {
                    error_log("PortalController: Failed to create directory: $storagePath");
                    return Response::serverError('Failed to create storage directory');
                }
            }

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'update_' . $updateId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $storagePath . $filename)) {
                error_log("PortalController: Failed to move uploaded file to: $storagePath$filename");
                return Response::serverError('Failed to store file');
            }

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $storagePath . $filename);
            finfo_close($finfo);

            $db = $this->portalService->getDb();
            $stmt = $db->prepare('
                INSERT INTO portal_update_files (update_id, filename, original_name, mime_type, file_size)
                VALUES (?, ?, ?, ?, ?)
            ');
            $stmt->execute([$updateId, $filename, $file['name'], $mimeType, $file['size']]);

            $fileId = (int)$db->lastInsertId();
            $stmt = $db->prepare('SELECT * FROM portal_update_files WHERE id = ?');
            $stmt->execute([$fileId]);

            return Response::success($stmt->fetch(), 'File attached');
        } catch (\Throwable $e) {
            error_log("PortalController::attachUpdateFile FAILED: " . $e->getMessage() . " | File: " . $e->getFile() . ":" . $e->getLine());
            return Response::serverError('Failed to attach file: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // Internal CRM: Document Management
    // =========================================================================

    /**
     * Create a document for a client
     * POST /clients/{id}/portal/documents
     */
    public function createDocument(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');

        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            return Response::badRequest('Document file is required');
        }

        $file = $_FILES['file'];
        $title = trim($request->input('title') ?? $file['name']);
        $description = $request->input('description');
        $documentType = $request->input('document_type') ?? 'other';
        $signingMethod = $request->input('signing_method') ?? 'both';
        $requiresAll = $request->input('requires_all_signers') !== null ? (bool)$request->input('requires_all_signers') : true;
        $signingDeadline = $request->input('signing_deadline');
        $amount = $request->input('amount');
        $currency = $request->input('currency') ?? 'HUF';
        $referenceNumber = $request->input('reference_number');
        $signers = $request->input('signers') ?? []; // array of {email, name, sign_order}

        // Validate file type
        $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($detectedMime, $allowedMimes)) {
            return Response::badRequest('Invalid file type');
        }

        if ($file['size'] > 20 * 1024 * 1024) {
            return Response::badRequest('File too large (max 20MB)');
        }

        // Attempt Drive-routed upload via client's board folder
        $driveFileId = null;
        $driveFile = null;
        try {
            $docDriveService = new \Webmail\Services\DocumentDriveService($this->config, $this->userEmail);
            $driveFile = $docDriveService->uploadToClientDrive($clientId, $documentType, $file);
            if ($driveFile) {
                $driveFileId = (int)$driveFile['id'];
            }
        } catch (\Exception $e) {
            error_log("DocumentDriveService upload failed, falling back to local: " . $e->getMessage());
        }

        // Fallback: store locally if Drive upload didn't succeed (no board linked, or Drive error)
        $fullPath = '';
        $filename = '';
        if (!$driveFile) {
            $storagePath = $this->getPortalStoragePath() . '/portal/' . $clientId . '/documents/';
            if (!is_dir($storagePath)) mkdir($storagePath, 0755, true);

            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'doc_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            $fullPath = $storagePath . $filename;
            move_uploaded_file($file['tmp_name'], $fullPath);
        } else {
            $filename = $driveFile['filename'] ?? '';
            $fullPath = $driveFile['full_path'] ?? '';
        }

        $db = $this->portalService->getDb();

        $stmt = $db->prepare('
            INSERT INTO portal_documents (client_id, created_by, title, description, document_type, 
                                         filename, original_name, mime_type, file_size, file_path,
                                         drive_file_id,
                                         signing_method, requires_all_signers, signing_deadline, 
                                         amount, currency, reference_number)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $clientId, $this->userEmail, $title, $description, $documentType,
            $filename, $file['name'], $detectedMime, $file['size'], $fullPath,
            $driveFileId,
            $signingMethod, $requiresAll ? 1 : 0, $signingDeadline,
            $amount, $currency, $referenceNumber
        ]);

        $docId = (int)$db->lastInsertId();

        // Add signers
        if (!empty($signers)) {
            $signerStmt = $db->prepare('
                INSERT INTO portal_document_signers (document_id, portal_access_id, signer_email, signer_name, sign_order)
                VALUES (?, ?, ?, ?, ?)
            ');
            foreach ($signers as $idx => $signer) {
                // Try to find portal access for this email
                $accessStmt = $db->prepare('SELECT id FROM portal_access WHERE client_id = ? AND email = ? AND is_active = 1');
                $accessStmt->execute([$clientId, strtolower($signer['email'])]);
                $accessRow = $accessStmt->fetch();
                $portalAccessId = $accessRow ? $accessRow['id'] : null;

                $signerStmt->execute([
                    $docId, $portalAccessId, strtolower($signer['email']),
                    $signer['name'] ?? null, $signer['sign_order'] ?? $idx
                ]);
            }
        }

        // Log audit
        $this->logDocumentAudit($db, $docId, 'created', 'internal', $this->userEmail, $request);

        $stmt = $db->prepare('SELECT * FROM portal_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $docData = $stmt->fetch();
        $docData['drive_uploaded'] = $driveFileId !== null;

        return Response::success($docData, 'Document created');
    }

    /**
     * Check if a client has a linked board with a Drive folder.
     * GET /clients/{id}/portal/check-board
     */
    public function checkClientBoard(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('
            SELECT cb.board_id, b.name AS board_name
            FROM client_boards cb
            JOIN webmail_boards b ON b.id = cb.board_id
            WHERE cb.client_id = ?
            ORDER BY cb.linked_at ASC
            LIMIT 1
        ');
        $stmt->execute([$clientId]);
        $link = $stmt->fetch();

        if (!$link) {
            return Response::success([
                'has_board' => false,
                'message' => 'This client has no linked board. Create a board first to enable Drive-based document storage.',
            ]);
        }

        return Response::success([
            'has_board' => true,
            'board_id' => (int)$link['board_id'],
            'board_name' => $link['board_name'],
        ]);
    }

    /**
     * Update document metadata
     * PUT /clients/{id}/portal/documents/{docId}
     */
    public function updateDocument(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('SELECT * FROM portal_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();

        if (!$doc) return Response::notFound('Document not found');

        // Only draft documents can be fully edited
        $isDraft = $doc['status'] === 'draft';

        $updates = [];
        $params = [];

        $fields = $isDraft
            ? ['title', 'description', 'document_type', 'signing_method', 'signing_deadline', 'amount', 'currency', 'reference_number']
            : ['description', 'signing_deadline'];

        foreach ($fields as $field) {
            $value = $request->input($field);
            if ($value !== null) {
                $updates[] = "{$field} = ?";
                $params[] = $value;
            }
        }

        if (empty($updates)) {
            return Response::badRequest('No fields to update');
        }

        $params[] = $docId;
        $db->prepare('UPDATE portal_documents SET ' . implode(', ', $updates) . ', updated_at = NOW() WHERE id = ?')
            ->execute($params);

        $stmt = $db->prepare('SELECT * FROM portal_documents WHERE id = ?');
        $stmt->execute([$docId]);

        return Response::success($stmt->fetch(), 'Document updated');
    }

    /**
     * Send document for signing (emails all signers)
     * POST /clients/{id}/portal/documents/{docId}/send
     */
    public function sendDocument(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('SELECT * FROM portal_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();

        if (!$doc) return Response::notFound('Document not found');
        if (!in_array($doc['status'], ['draft', 'sent', 'viewed'])) {
            return Response::badRequest('Document cannot be sent in current status');
        }

        $clientId = (int)$doc['client_id'];

        // Auto-assign active portal users as signers if none exist
        $signerCount = $db->prepare('SELECT COUNT(*) FROM portal_document_signers WHERE document_id = ?');
        $signerCount->execute([$docId]);
        if ((int)$signerCount->fetchColumn() === 0) {
            $accessStmt = $db->prepare('SELECT id, email, name FROM portal_access WHERE client_id = ? AND is_active = 1');
            $accessStmt->execute([$clientId]);
            $portalUsers = $accessStmt->fetchAll();

            if (!empty($portalUsers)) {
                $insertSigner = $db->prepare('
                    INSERT INTO portal_document_signers (document_id, portal_access_id, signer_email, signer_name, sign_order)
                    VALUES (?, ?, ?, ?, 0)
                ');
                foreach ($portalUsers as $pu) {
                    $insertSigner->execute([$docId, $pu['id'], strtolower($pu['email']), $pu['name'] ?? '']);
                }
                error_log("sendDocument: Auto-assigned " . count($portalUsers) . " portal user(s) as signers for doc $docId");
            }
        }

        // Update status to sent
        $db->prepare('UPDATE portal_documents SET status = ?, sent_at = NOW(), updated_at = NOW() WHERE id = ?')
            ->execute(['sent', $docId]);

        // Log audit
        $this->logDocumentAudit($db, $docId, 'sent', 'internal', $this->userEmail, $request);

        // TODO: Send email notifications to signers

        return Response::success(null, 'Document sent for signing');
    }

    /**
     * Send reminder to pending signers
     * POST /clients/{id}/portal/documents/{docId}/remind
     */
    public function remindDocument(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        // Get pending signers
        $stmt = $db->prepare('
            SELECT * FROM portal_document_signers 
            WHERE document_id = ? AND status = ?
        ');
        $stmt->execute([$docId, 'pending']);
        $pendingSigners = $stmt->fetchAll();

        if (empty($pendingSigners)) {
            return Response::badRequest('No pending signers');
        }

        // Update reminder tracking
        foreach ($pendingSigners as $signer) {
            $db->prepare('UPDATE portal_document_signers SET reminder_count = reminder_count + 1, last_reminder_at = NOW() WHERE id = ?')
                ->execute([$signer['id']]);
        }

        $db->prepare('UPDATE portal_documents SET reminder_sent_at = NOW() WHERE id = ?')
            ->execute([$docId]);

        // Log audit
        $this->logDocumentAudit($db, $docId, 'reminder_sent', 'internal', $this->userEmail, $request);

        // TODO: Send reminder emails

        return Response::success(['reminded' => count($pendingSigners)], 'Reminders sent');
    }

    /**
     * List documents for a client (internal view)
     * GET /clients/{id}/portal/documents
     */
    public function listClientDocuments(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('
            SELECT pd.*,
                   (SELECT COUNT(*) FROM portal_document_signers pds WHERE pds.document_id = pd.id) as signer_count,
                   (SELECT COUNT(*) FROM portal_document_signers pds WHERE pds.document_id = pd.id AND pds.status = ?) as signed_count,
                   (SELECT COUNT(*) FROM portal_document_signers pds WHERE pds.document_id = pd.id AND pds.status = ?) as pending_count
            FROM portal_documents pd
            WHERE pd.client_id = ?
            ORDER BY pd.created_at DESC
        ');
        $stmt->execute(['signed', 'pending', $clientId]);

        return Response::success(['documents' => $stmt->fetchAll()]);
    }

    /**
     * Get document audit trail
     * GET /clients/{id}/portal/documents/{docId}/audit
     */
    public function getDocumentAudit(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('
            SELECT * FROM portal_document_audit 
            WHERE document_id = ? 
            ORDER BY created_at DESC
        ');
        $stmt->execute([$docId]);

        return Response::success(['audit' => $stmt->fetchAll()]);
    }

    /**
     * Download signed file from a signer
     * GET /clients/{id}/portal/documents/{docId}/signed-file/{signerId}
     */
    public function downloadSignedFile(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $signerId = (int)$request->getParam('signerId');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('SELECT * FROM portal_document_signers WHERE id = ? AND status = ?');
        $stmt->execute([$signerId, 'signed']);
        $signer = $stmt->fetch();

        if (!$signer || !$signer['uploaded_file_path']) {
            return Response::notFound('Signed file not found');
        }

        if (!file_exists($signer['uploaded_file_path'])) {
            return Response::notFound('File not found on disk');
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . addslashes($signer['uploaded_filename'] ?? 'signed_document') . '"');
        header('Content-Length: ' . filesize($signer['uploaded_file_path']));
        readfile($signer['uploaded_file_path']);
        exit;
    }

    // =========================================================================
    // Internal CRM: Document Zones
    // =========================================================================

    /**
     * Save signature/stamp zones for a document
     * POST /clients/{id}/portal/documents/{docId}/zones
     */
    public function saveZones(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');
        $zones = $request->input('zones');

        if (!is_array($zones)) {
            return Response::badRequest('Zones array is required');
        }

        $db = $this->portalService->getDb();

        $stmt = $db->prepare('SELECT id, status FROM portal_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();
        if (!$doc) return Response::notFound('Document not found');

        $db->beginTransaction();
        try {
            $db->prepare('DELETE FROM portal_document_zones WHERE document_id = ?')->execute([$docId]);

            $insertStmt = $db->prepare('
                INSERT INTO portal_document_zones (document_id, signer_email, zone_type, page_number, x_percent, y_percent, width_percent, height_percent, label)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');

            foreach ($zones as $zone) {
                $insertStmt->execute([
                    $docId,
                    $zone['signer_email'] ?? null,
                    $zone['zone_type'] ?? 'signature',
                    (int)($zone['page_number'] ?? 1),
                    (float)($zone['x_percent'] ?? 0),
                    (float)($zone['y_percent'] ?? 0),
                    (float)($zone['width_percent'] ?? 10),
                    (float)($zone['height_percent'] ?? 5),
                    $zone['label'] ?? null,
                ]);
            }

            // Link signer_id from signer_email
            $db->prepare('
                UPDATE portal_document_zones z
                JOIN portal_document_signers s ON s.document_id = z.document_id AND s.signer_email = z.signer_email
                SET z.signer_id = s.id
                WHERE z.document_id = ?
            ')->execute([$docId]);

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("PortalController: saveZones error: " . $e->getMessage());
            return Response::serverError('Failed to save zones');
        }

        return Response::success(null, 'Zones saved');
    }

    /**
     * Get zones for a document (internal CRM)
     * GET /clients/{id}/portal/documents/{docId}/zones
     */
    public function getZones(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        try {
            $stmt = $db->prepare('SELECT * FROM portal_document_zones WHERE document_id = ? ORDER BY page_number, id');
            $stmt->execute([$docId]);
            return Response::success(['zones' => $stmt->fetchAll()]);
        } catch (\Throwable $e) {
            error_log("getZones error for doc $docId: " . $e->getMessage());
            return Response::success(['zones' => []]);
        }
    }

    /**
     * Get zones for a document (portal-authenticated)
     * GET /portal/documents/{docId}/zones
     */
    public function getPortalZones(Request $request): Response
    {
        $portalUser = $this->requirePortalAuth($request);
        if ($portalUser instanceof Response) return $portalUser;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        // Verify document belongs to this client
        $stmt = $db->prepare('SELECT id FROM portal_documents WHERE id = ? AND client_id = ?');
        $stmt->execute([$docId, $portalUser['client_id']]);
        if (!$stmt->fetch()) return Response::notFound('Document not found');

        $stmt = $db->prepare('SELECT * FROM portal_document_zones WHERE document_id = ? ORDER BY page_number, id');
        $stmt->execute([$docId]);

        return Response::success(['zones' => $stmt->fetchAll()]);
    }

    /**
     * Download document file for internal use (zone editor PDF preview)
     * GET /clients/{id}/portal/documents/{docId}/download-internal
     */
    public function downloadDocumentInternal(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('SELECT * FROM portal_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();

        if (!$doc) return Response::notFound('Document not found');

        $filePath = $doc['file_path'];
        if (!file_exists($filePath)) {
            $clientId = (int)$doc['client_id'];
            $relativePath = '/portal/' . $clientId . '/documents/' . $doc['filename'];
            $filePath = $this->resolvePortalFile($relativePath);
            if (!$filePath) return Response::notFound('File not found on disk');
        }

        header('Content-Type: ' . ($doc['mime_type'] ?? 'application/octet-stream'));
        header('Content-Disposition: inline; filename="' . addslashes($doc['original_name']) . '"');
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=3600');
        readfile($filePath);
        exit;
    }

    /**
     * Download the final signed PDF
     * GET /clients/{id}/portal/documents/{docId}/signed-pdf
     */
    public function downloadSignedPdf(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('SELECT * FROM portal_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();

        if (!$doc) return Response::notFound('Document not found');
        if (!$doc['signed_file_path'] || !file_exists($doc['signed_file_path'])) {
            return Response::notFound('Signed PDF not yet available');
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . addslashes($doc['signed_filename'] ?? 'signed_document.pdf') . '"');
        header('Content-Length: ' . filesize($doc['signed_file_path']));
        readfile($doc['signed_file_path']);
        exit;
    }

    // =========================================================================
    // Document Annotations (CRM internal)
    // =========================================================================

    public function getAnnotations(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        $annotations = $db->prepare('
            SELECT a.*, 
                   (SELECT COUNT(*) FROM portal_annotation_comments c WHERE c.annotation_id = a.id) AS comment_count
            FROM portal_document_annotations a
            WHERE a.document_id = ?
            ORDER BY a.page_number, a.created_at
        ');
        $annotations->execute([$docId]);
        $pins = $annotations->fetchAll();

        foreach ($pins as &$pin) {
            $commentsStmt = $db->prepare('
                SELECT c.*
                FROM portal_annotation_comments c
                WHERE c.annotation_id = ?
                ORDER BY c.created_at ASC
            ');
            $commentsStmt->execute([$pin['id']]);
            $pin['comments'] = $commentsStmt->fetchAll();
            foreach ($pin['comments'] as &$comment) {
                $attStmt = $db->prepare('SELECT id, original_name, mime_type, file_size FROM portal_annotation_attachments WHERE comment_id = ?');
                $attStmt->execute([$comment['id']]);
                $comment['attachments'] = $attStmt->fetchAll();
            }
        }

        return Response::success(['annotations' => $pins]);
    }

    public function createAnnotation(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        $pageNumber = (int)($request->input('page_number') ?? 1);
        $xPercent = (float)$request->input('x_percent');
        $yPercent = (float)$request->input('y_percent');
        $content = trim($request->input('content') ?? '');

        if ($xPercent < 0 || $xPercent > 100 || $yPercent < 0 || $yPercent > 100) {
            return Response::badRequest('Invalid pin position');
        }
        if (empty($content)) {
            return Response::badRequest('Comment text is required');
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('
                INSERT INTO portal_document_annotations (document_id, page_number, x_percent, y_percent, created_by_email, created_by_name, created_by_type)
                VALUES (?, ?, ?, ?, ?, ?, "internal")
            ');
            $stmt->execute([$docId, $pageNumber, $xPercent, $yPercent, $this->userEmail, $this->userEmail]);
            $annotationId = (int)$db->lastInsertId();

            $stmt = $db->prepare('
                INSERT INTO portal_annotation_comments (annotation_id, author_email, author_name, author_type, content)
                VALUES (?, ?, ?, "internal", ?)
            ');
            $stmt->execute([$annotationId, $this->userEmail, $this->userEmail, $content]);
            $commentId = (int)$db->lastInsertId();

            $this->handleAnnotationAttachments($db, $commentId, $docId, $request);

            $db->commit();

            $this->logDocumentAudit($db, $docId, 'annotation_added', 'internal', $this->userEmail, $request);

            return Response::success(['annotation_id' => $annotationId, 'comment_id' => $commentId], 'Annotation created');
        } catch (\Exception $e) {
            $db->rollBack();
            return Response::error('Failed to create annotation: ' . $e->getMessage());
        }
    }

    public function updateAnnotation(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $annotationId = (int)$request->getParam('annotationId');
        $status = $request->input('status');
        $db = $this->portalService->getDb();

        if ($status && in_array($status, ['open', 'resolved'])) {
            $db->prepare('UPDATE portal_document_annotations SET status = ? WHERE id = ?')
                ->execute([$status, $annotationId]);
        }

        return Response::success(null, 'Annotation updated');
    }

    public function deleteAnnotation(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $annotationId = (int)$request->getParam('annotationId');
        $db = $this->portalService->getDb();

        $db->prepare('DELETE FROM portal_document_annotations WHERE id = ?')
            ->execute([$annotationId]);

        return Response::success(null, 'Annotation deleted');
    }

    public function createAnnotationComment(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $annotationId = (int)$request->getParam('annotationId');
        $docId = (int)$request->getParam('docId');
        $content = trim($request->input('content') ?? '');

        if (empty($content)) {
            return Response::badRequest('Comment text is required');
        }

        $db = $this->portalService->getDb();

        $stmt = $db->prepare('
            INSERT INTO portal_annotation_comments (annotation_id, author_email, author_name, author_type, content)
            VALUES (?, ?, ?, "internal", ?)
        ');
        $stmt->execute([$annotationId, $this->userEmail, $this->userEmail, $content]);
        $commentId = (int)$db->lastInsertId();

        $this->handleAnnotationAttachments($db, $commentId, $docId, $request);

        return Response::success(['comment_id' => $commentId], 'Comment added');
    }

    public function deleteAnnotationComment(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $commentId = (int)$request->getParam('commentId');
        $db = $this->portalService->getDb();

        $db->prepare('DELETE FROM portal_annotation_comments WHERE id = ?')
            ->execute([$commentId]);

        return Response::success(null, 'Comment deleted');
    }

    // =========================================================================
    // Document Annotations (Portal side)
    // =========================================================================

    public function getPortalAnnotations(Request $request): Response
    {
        $portalUser = $this->getPortalUser($request);
        if (!$portalUser) return Response::unauthorized('Portal auth required');

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        $annotations = $db->prepare('
            SELECT a.*, 
                   (SELECT COUNT(*) FROM portal_annotation_comments c WHERE c.annotation_id = a.id) AS comment_count
            FROM portal_document_annotations a
            WHERE a.document_id = ?
            ORDER BY a.page_number, a.created_at
        ');
        $annotations->execute([$docId]);
        $pins = $annotations->fetchAll();

        foreach ($pins as &$pin) {
            $commentsStmt = $db->prepare('
                SELECT c.*
                FROM portal_annotation_comments c
                WHERE c.annotation_id = ?
                ORDER BY c.created_at ASC
            ');
            $commentsStmt->execute([$pin['id']]);
            $pin['comments'] = $commentsStmt->fetchAll();
            foreach ($pin['comments'] as &$comment) {
                $attStmt = $db->prepare('SELECT id, original_name, mime_type, file_size FROM portal_annotation_attachments WHERE comment_id = ?');
                $attStmt->execute([$comment['id']]);
                $comment['attachments'] = $attStmt->fetchAll();
            }
        }

        return Response::success(['annotations' => $pins]);
    }

    public function createPortalAnnotation(Request $request): Response
    {
        $portalUser = $this->getPortalUser($request);
        if (!$portalUser) return Response::unauthorized('Portal auth required');

        $docId = (int)$request->getParam('docId');
        $db = $this->portalService->getDb();

        $pageNumber = (int)($request->input('page_number') ?? 1);
        $xPercent = (float)$request->input('x_percent');
        $yPercent = (float)$request->input('y_percent');
        $content = trim($request->input('content') ?? '');

        if ($xPercent < 0 || $xPercent > 100 || $yPercent < 0 || $yPercent > 100) {
            return Response::badRequest('Invalid pin position');
        }
        if (empty($content)) {
            return Response::badRequest('Comment text is required');
        }

        $db->beginTransaction();
        try {
            $stmt = $db->prepare('
                INSERT INTO portal_document_annotations (document_id, page_number, x_percent, y_percent, created_by_email, created_by_name, created_by_type)
                VALUES (?, ?, ?, ?, ?, ?, "portal")
            ');
            $stmt->execute([$docId, $pageNumber, $xPercent, $yPercent, $portalUser['email'], $portalUser['name'] ?? $portalUser['email']]);
            $annotationId = (int)$db->lastInsertId();

            $stmt = $db->prepare('
                INSERT INTO portal_annotation_comments (annotation_id, author_email, author_name, author_type, content)
                VALUES (?, ?, ?, "portal", ?)
            ');
            $stmt->execute([$annotationId, $portalUser['email'], $portalUser['name'] ?? $portalUser['email'], $content]);
            $commentId = (int)$db->lastInsertId();

            $this->handleAnnotationAttachments($db, $commentId, $docId, $request);

            $db->commit();

            $this->logDocumentAudit($db, $docId, 'annotation_added', 'portal', $portalUser['email'], $request);

            return Response::success(['annotation_id' => $annotationId, 'comment_id' => $commentId], 'Annotation created');
        } catch (\Exception $e) {
            $db->rollBack();
            return Response::error('Failed to create annotation: ' . $e->getMessage());
        }
    }

    public function createPortalAnnotationComment(Request $request): Response
    {
        $portalUser = $this->getPortalUser($request);
        if (!$portalUser) return Response::unauthorized('Portal auth required');

        $annotationId = (int)$request->getParam('annotationId');
        $docId = (int)$request->getParam('docId');
        $content = trim($request->input('content') ?? '');

        if (empty($content)) {
            return Response::badRequest('Comment text is required');
        }

        $db = $this->portalService->getDb();

        $stmt = $db->prepare('
            INSERT INTO portal_annotation_comments (annotation_id, author_email, author_name, author_type, content)
            VALUES (?, ?, ?, "portal", ?)
        ');
        $stmt->execute([$annotationId, $portalUser['email'], $portalUser['name'] ?? $portalUser['email'], $content]);
        $commentId = (int)$db->lastInsertId();

        $this->handleAnnotationAttachments($db, $commentId, $docId, $request);

        return Response::success(['comment_id' => $commentId], 'Comment added');
    }

    /**
     * Handle file attachments for annotation comments.
     */
    private function handleAnnotationAttachments(\PDO $db, int $commentId, int $docId, Request $request): void
    {
        if (empty($_FILES['attachment']) || $_FILES['attachment']['error'] === UPLOAD_ERR_NO_FILE) {
            return;
        }

        $files = $_FILES['attachment'];
        $fileList = is_array($files['name']) ? $this->normalizeFileArray($files) : [$files];

        $docStmt = $db->prepare('SELECT client_id FROM portal_documents WHERE id = ?');
        $docStmt->execute([$docId]);
        $doc = $docStmt->fetch();
        $clientId = $doc ? (int)$doc['client_id'] : 0;

        foreach ($fileList as $file) {
            if ($file['error'] !== UPLOAD_ERR_OK) continue;

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            $driveFileId = null;
            $filePath = '';
            $storedFilename = '';

            try {
                $docDriveService = new \Webmail\Services\DocumentDriveService($this->config, $this->userEmail ?? '');
                $driveFile = $docDriveService->uploadAnnotationAttachment(
                    $clientId,
                    $file['tmp_name'],
                    $file['name']
                );
                if ($driveFile) {
                    $driveFileId = (int)$driveFile['id'];
                    $storedFilename = $driveFile['filename'] ?? '';
                    $filePath = $driveFile['full_path'] ?? '';
                }
            } catch (\Exception $e) {
                error_log("Annotation attachment Drive upload failed: " . $e->getMessage());
            }

            if (!$driveFileId) {
                $storagePath = ($this->config['storage_path'] ?? __DIR__ . '/../../storage')
                    . '/portal/' . $clientId . '/annotations/';
                if (!is_dir($storagePath)) mkdir($storagePath, 0755, true);

                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $storedFilename = 'ann_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $filePath = $storagePath . $storedFilename;
                move_uploaded_file($file['tmp_name'], $filePath);
            }

            $db->prepare('
                INSERT INTO portal_annotation_attachments (comment_id, filename, original_name, mime_type, file_size, file_path, drive_file_id)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $commentId, $storedFilename, $file['name'], $mime, $file['size'], $filePath, $driveFileId
            ]);
        }
    }

    private function normalizeFileArray(array $files): array
    {
        $result = [];
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $result[] = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i],
            ];
        }
        return $result;
    }

    // =========================================================================
    // Document View Together (CRM internal)
    // =========================================================================

    public function startDocViewSession(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');

        $session = [
            'document_id' => $docId,
            'started_by' => $this->userEmail,
            'started_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $redis = new \Webmail\Services\RedisCacheService($this->config);
            $redis->set("doc_view_session:{$docId}", json_encode($session), 3600);

            $db = $this->portalService->getDb();
            $stmt = $db->prepare('SELECT created_by FROM portal_documents WHERE id = ?');
            $stmt->execute([$docId]);
            $doc = $stmt->fetch();

            $recipients = [$this->userEmail];
            if ($doc && $doc['created_by'] && $doc['created_by'] !== $this->userEmail) {
                $recipients[] = $doc['created_by'];
            }

            foreach ($recipients as $email) {
                $redis->publishEvent($email, 'DOC_VIEW_SESSION_START', $session);
            }
        } catch (\Throwable $e) {
            error_log("startDocViewSession: " . $e->getMessage());
        }

        return Response::success($session, 'View session started');
    }

    public function endDocViewSession(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');

        try {
            $redis = new \Webmail\Services\RedisCacheService($this->config);
            $sessionKey = "doc_view_session:{$docId}";
            $redis->del($sessionKey);

            $db = $this->portalService->getDb();
            $stmt = $db->prepare('SELECT created_by FROM portal_documents WHERE id = ?');
            $stmt->execute([$docId]);
            $doc = $stmt->fetch();

            $recipients = [$this->userEmail];
            if ($doc && $doc['created_by'] && $doc['created_by'] !== $this->userEmail) {
                $recipients[] = $doc['created_by'];
            }

            foreach ($recipients as $email) {
                $redis->publishEvent($email, 'DOC_VIEW_SESSION_END', [
                    'document_id' => $docId,
                    'ended_by' => $this->userEmail,
                ]);
            }
        } catch (\Throwable $e) {
            error_log("endDocViewSession: " . $e->getMessage());
        }

        return Response::success(null, 'View session ended');
    }

    public function syncDocViewPosition(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $docId = (int)$request->getParam('docId');

        try {
            $redis = new \Webmail\Services\RedisCacheService($this->config);

            $payload = [
                'document_id' => $docId,
                'user' => $this->userEmail,
                'timestamp' => round(microtime(true) * 1000),
            ];

            if ($request->input('position')) $payload['position'] = $request->input('position');
            if ($request->input('cursor')) $payload['cursor'] = $request->input('cursor');

            $db = $this->portalService->getDb();
            $stmt = $db->prepare('SELECT created_by FROM portal_documents WHERE id = ?');
            $stmt->execute([$docId]);
            $doc = $stmt->fetch();

            $recipients = [$this->userEmail];
            if ($doc && $doc['created_by'] && $doc['created_by'] !== $this->userEmail) {
                $recipients[] = $doc['created_by'];
            }

            foreach ($recipients as $email) {
                $redis->publishEvent($email, 'DOC_VIEW_SYNC', $payload);
            }
        } catch (\Throwable $e) {
            error_log("syncDocViewPosition: " . $e->getMessage());
        }

        return Response::success(null);
    }

    // =========================================================================
    // Internal CRM: Calls
    // =========================================================================

    /**
     * Create a portal call room
     * POST /clients/{id}/portal/calls
     */
    public function createCall(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $callType = $request->input('call_type') ?? 'instant';
        $scheduledAt = $request->input('scheduled_at');
        $boardId = $request->input('board_id') ? (int)$request->input('board_id') : null;
        $cardId = $request->input('card_id') ? (int)$request->input('card_id') : null;

        $env = preg_replace('/[^a-z0-9_-]/i', '', (string)($this->config['app']['env'] ?? getenv('APP_ENV') ?: 'prod')) ?: 'prod';
        $roomName = $env . '_portal_' . $clientId . '_' . bin2hex(random_bytes(8));

        $db = $this->portalService->getDb();
        $stmt = $db->prepare('
            INSERT INTO portal_calls (client_id, board_id, card_id, created_by, room_name, call_type, scheduled_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$clientId, $boardId, $cardId, $this->userEmail, $roomName, $callType, $scheduledAt]);

        $callId = (int)$db->lastInsertId();

        $this->addCallParticipant($db, $callId, 'internal', $this->userEmail);

        $waiting = (bool)$request->input('waiting_room', false);
        $hidden = (bool)$request->input('participants_hidden', false);
        try {
            $mr = new MeetingRoomService($this->config);
            $mr->ensureRoom($roomName, $waiting, $hidden, $this->userEmail);
        } catch (\Throwable $e) {
            error_log('PortalController createCall meeting_rooms: ' . $e->getMessage());
        }

        $stmt = $db->prepare('SELECT * FROM portal_calls WHERE id = ?');
        $stmt->execute([$callId]);

        return Response::success($stmt->fetch(), 'Call created');
    }

    /**
     * List calls for a client (CRM side)
     * GET /clients/{id}/portal/calls
     */
    public function listClientCalls(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $clientId = (int)$request->getParam('id');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare("
            SELECT pc.id, pc.client_id, pc.board_id, pc.card_id, pc.created_by,
                   pc.room_name, pc.call_type, pc.status,
                   pc.scheduled_at, pc.started_at, pc.ended_at, pc.duration_seconds,
                   pc.had_screen_share, pc.notes, pc.transcript_sent_at,
                   (pc.chat_transcript IS NOT NULL AND pc.chat_transcript != '') as chat_transcript,
                   pc.created_at,
                   b.name AS board_name,
                   c.title AS card_title
            FROM portal_calls pc
            LEFT JOIN webmail_boards b ON b.id = pc.board_id
            LEFT JOIN webmail_board_cards c ON c.id = pc.card_id
            WHERE pc.client_id = ?
            ORDER BY pc.created_at DESC
        ");
        $stmt->execute([$clientId]);
        $calls = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $callIds = array_column($calls, 'id');
        $participantMap = [];
        if (!empty($callIds)) {
            $ph = implode(',', array_fill(0, count($callIds), '?'));
            $pStmt = $db->prepare("
                SELECT call_id, participant_type, email, display_name, duration_seconds
                FROM portal_call_participants
                WHERE call_id IN ($ph)
                ORDER BY joined_at ASC
            ");
            $pStmt->execute($callIds);
            foreach ($pStmt->fetchAll(\PDO::FETCH_ASSOC) as $p) {
                $participantMap[(int)$p['call_id']][] = $p;
            }
        }

        foreach ($calls as &$call) {
            $call['participants'] = $participantMap[(int)$call['id']] ?? [];
        }
        unset($call);

        return Response::success(['calls' => $calls]);
    }

    /**
     * End a portal call (CRM side)
     * POST /clients/{id}/portal/calls/{callId}/end
     */
    public function endClientCall(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $callId = (int)$request->getParam('callId');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('SELECT * FROM portal_calls WHERE id = ?');
        $stmt->execute([$callId]);
        $call = $stmt->fetch();

        if (!$call) {
            return Response::notFound('Call not found');
        }

        $db->prepare('
            UPDATE portal_calls SET status = ?, ended_at = NOW(), 
            duration_seconds = TIMESTAMPDIFF(SECOND, COALESCE(started_at, created_at), NOW())
            WHERE id = ?
        ')->execute(['ended', $callId]);

        $this->trackCallTime($db, $call);
        $this->bridgeCallToWorkSession($db, $call);
        $this->finalizeCallParticipants($db, $callId);

        return Response::success(null, 'Call ended');
    }

    /**
     * Cancel a scheduled/waiting call
     * POST /clients/{id}/portal/calls/{callId}/cancel
     */
    public function cancelCall(Request $request): Response
    {
        $authCheck = $this->requireAuth($request);
        if ($authCheck) return $authCheck;

        $callId = (int)$request->getParam('callId');
        $db = $this->portalService->getDb();

        $stmt = $db->prepare('SELECT * FROM portal_calls WHERE id = ?');
        $stmt->execute([$callId]);
        $call = $stmt->fetch();

        if (!$call) {
            return Response::notFound('Call not found');
        }

        if ($call['status'] === 'ended' || $call['status'] === 'cancelled') {
            return Response::badRequest('Call is already ' . $call['status']);
        }

        $db->prepare('
            UPDATE portal_calls SET status = ?, ended_at = NOW() WHERE id = ?
        ')->execute(['cancelled', $callId]);

        return Response::success(null, 'Call cancelled');
    }

    // =========================================================================
    // Call Time Tracking
    // =========================================================================

    /**
     * Record portal call duration in the client time tracking table.
     * Uses the same upsert pattern as ClientTimeTrackingService so multiple
     * calls on the same day accumulate rather than overwrite.
     */
    private function trackCallTime(\PDO $db, array $call): void
    {
        try {
            $startRef = $call['started_at'] ?? $call['created_at'];
            $durationSeconds = (int)(time() - strtotime($startRef));
            if ($durationSeconds < 5) return;

            $db->prepare('
                INSERT INTO webmail_client_time_tracking 
                    (user_email, client_id, activity_type, entity_id, entity_name, duration_seconds, tracked_date)
                VALUES (?, ?, ?, ?, ?, ?, CURDATE())
                ON DUPLICATE KEY UPDATE 
                    duration_seconds = duration_seconds + VALUES(duration_seconds),
                    updated_at = CURRENT_TIMESTAMP
            ')->execute([
                $call['created_by'],
                $call['client_id'],
                'client_call',
                (string)$call['id'],
                $call['call_type'] === 'scheduled' ? 'Scheduled call' : 'Video call',
                $durationSeconds,
            ]);
        } catch (\Throwable $e) {
            error_log("PortalController::trackCallTime failed: " . $e->getMessage());
        }
    }

    /**
     * Bridge a portal call into projecthub_work_sessions when the call is
     * linked to a card. Fires once when the call ends.
     */
    private function bridgeCallToWorkSession(\PDO $db, array $call): void
    {
        if (empty($call['card_id'])) return;

        try {
            $startRef = $call['started_at'] ?? $call['created_at'];
            $durationSeconds = (int)(time() - strtotime($startRef));
            if ($durationSeconds < 5) return;

            $db->prepare("
                INSERT INTO projecthub_work_sessions
                    (card_id, user_email, source, entity_type, entity_id, entity_name, started_at, ended_at, duration_seconds)
                VALUES (?, ?, 'portal_call', 'portal_call', ?, ?, ?, NOW(), ?)
            ")->execute([
                (int)$call['card_id'],
                $call['created_by'],
                (int)$call['id'],
                $call['call_type'] === 'scheduled' ? 'Scheduled call' : 'Video call',
                $startRef,
                $durationSeconds,
            ]);
        } catch (\Throwable $e) {
            error_log("PortalController::bridgeCallToWorkSession failed: " . $e->getMessage());
        }
    }

    /**
     * Insert a participant row for a portal call.
     */
    private function addCallParticipant(\PDO $db, int $callId, string $type, string $email, ?string $displayName = null): void
    {
        try {
            $db->prepare("
                INSERT IGNORE INTO portal_call_participants (call_id, participant_type, email, display_name, joined_at)
                VALUES (?, ?, ?, ?, NOW())
            ")->execute([$callId, $type, strtolower($email), $displayName]);
        } catch (\Throwable $e) {
            error_log("PortalController::addCallParticipant failed: " . $e->getMessage());
        }
    }

    /**
     * Finalize all active participants of a call: set left_at and compute duration.
     */
    private function finalizeCallParticipants(\PDO $db, int $callId): void
    {
        try {
            $db->prepare("
                UPDATE portal_call_participants
                SET left_at = NOW(),
                    duration_seconds = GREATEST(0, TIMESTAMPDIFF(SECOND, joined_at, NOW()))
                WHERE call_id = ? AND left_at IS NULL
            ")->execute([$callId]);
        } catch (\Throwable $e) {
            error_log("PortalController::finalizeCallParticipants failed: " . $e->getMessage());
        }
    }

    // =========================================================================
    // Portal Auth Helpers
    // =========================================================================

    /**
     * Extract portal token from request
     */
    private function getPortalToken(Request $request): ?string
    {
        return $request->getHeader('X-Portal-Token')
            ?? $request->getQuery('portal_token')
            ?? null;
    }

    /**
     * Validate portal session and return portal user info
     * Returns Response on failure, array on success
     */
    private function requirePortalAuth(Request $request): array|Response
    {
        $token = $this->getPortalToken($request);
        if (!$token) {
            return Response::unauthorized('Portal session token is required');
        }

        $portalUser = $this->portalService->validateSession($token);
        if (!$portalUser) {
            return Response::unauthorized('Invalid or expired portal session');
        }

        return $portalUser;
    }

    // =========================================================================
    // Document Helpers
    // =========================================================================

    /**
     * Generate a signed PDF by overlaying the signature/stamp at zone coordinates.
     * Implements chain-signing: uses the latest signed version as the base PDF.
     */
    private function generateSignedPdf(\PDO $db, int $docId, array $signer, array $portalUser, ?string $signatureData, ?string $stampData, Request $request): void
    {
        try {
            // Fetch zones for this signer (assigned) + unassigned zones (signer_id IS NULL AND signer_email IS NULL)
            $stmt = $db->prepare('
                SELECT * FROM portal_document_zones 
                WHERE document_id = ? AND (
                    signer_id = ? 
                    OR signer_email = ? 
                    OR (signer_id IS NULL AND (signer_email IS NULL OR signer_email = \'\'))
                )
                ORDER BY page_number, id
            ');
            $stmt->execute([$docId, $signer['id'], strtolower($portalUser['email'])]);
            $zones = $stmt->fetchAll();

            if (empty($zones)) return;

            $pdfService = new \Webmail\Services\PdfSigningService($this->config);

            $basePdf = $pdfService->getBasePdfPath($db, $docId);
            $clientId = (int)$portalUser['client_id'];
            $outputPath = $pdfService->generateSignedPath($clientId, $docId, $signer['id']);

            $signerData = [
                'signature_data' => $signatureData,
                'stamp_data' => $stampData,
                'name' => $signer['signer_name'] ?? $portalUser['name'] ?? '',
                'email' => $portalUser['email'],
                'signed_at' => date('Y-m-d H:i:s'),
                'ip' => $request->getClientIp(),
            ];

            $pdfService->overlaySignatures($basePdf, $zones, $signerData, $outputPath);

            // Update document with the latest signed PDF path
            $signedFilename = 'signed_' . basename($outputPath);
            $db->prepare('UPDATE portal_documents SET signed_file_path = ?, signed_filename = ? WHERE id = ?')
                ->execute([$outputPath, $signedFilename, $docId]);

        } catch (\Throwable $e) {
            error_log("PortalController: generateSignedPdf error for doc $docId: " . $e->getMessage());
        }
    }

    /**
     * Get the current portal user's signer record for a document
     */
    private function getMySignerRecord(\PDO $db, int $docId, array $portalUser): ?array
    {
        $stmt = $db->prepare('
            SELECT pds.* FROM portal_document_signers pds
            JOIN portal_documents pd ON pds.document_id = pd.id
            WHERE pds.document_id = ? AND pd.client_id = ? 
            AND (pds.portal_access_id = ? OR pds.signer_email = ?)
        ');
        $stmt->execute([$docId, $portalUser['client_id'], $portalUser['portal_access_id'], $portalUser['email']]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Check if sequential signing order allows current signer
     */
    private function checkSigningOrder(\PDO $db, int $docId, int $currentOrder): ?string
    {
        $stmt = $db->prepare('
            SELECT COUNT(*) FROM portal_document_signers 
            WHERE document_id = ? AND sign_order > 0 AND sign_order < ? AND status != ?
        ');
        $stmt->execute([$docId, $currentOrder, 'signed']);
        $pendingBefore = (int)$stmt->fetchColumn();

        if ($pendingBefore > 0) {
            return 'Waiting for previous signers to complete before you can sign.';
        }

        return null;
    }

    /**
     * Check if all signers have signed and update document status
     */
    private function checkDocumentCompletion(\PDO $db, int $docId): void
    {
        $stmt = $db->prepare('SELECT requires_all_signers FROM portal_documents WHERE id = ?');
        $stmt->execute([$docId]);
        $doc = $stmt->fetch();

        if (!$doc) return;

        if ($doc['requires_all_signers']) {
            $stmt = $db->prepare('
                SELECT COUNT(*) FROM portal_document_signers WHERE document_id = ? AND status != ?
            ');
            $stmt->execute([$docId, 'signed']);
            $unsigned = (int)$stmt->fetchColumn();

            if ($unsigned === 0) {
                $db->prepare('UPDATE portal_documents SET status = ?, completed_at = NOW() WHERE id = ?')
                    ->execute(['signed', $docId]);
            } else {
                $db->prepare('UPDATE portal_documents SET status = ? WHERE id = ? AND status != ?')
                    ->execute(['signing', $docId, 'signed']);
            }
        } else {
            // If any signer has signed, mark as signed
            $stmt = $db->prepare('SELECT COUNT(*) FROM portal_document_signers WHERE document_id = ? AND status = ?');
            $stmt->execute([$docId, 'signed']);
            if ((int)$stmt->fetchColumn() > 0) {
                $db->prepare('UPDATE portal_documents SET status = ?, completed_at = NOW() WHERE id = ?')
                    ->execute(['signed', $docId]);
            }
        }
    }

    /**
     * Get the storage base path for portal files.
     * Falls back to backend/storage if config key is missing.
     */
    private function getPortalStoragePath(): string
    {
        return $this->config['storage_path']
            ?? dirname(__DIR__, 4) . '/storage';
    }

    /**
     * Try to find a portal file across possible storage locations.
     * Handles files uploaded before/after storage path config was added.
     * @param string $relativePath e.g. '/portal/1/updates/filename.mp3'
     * @return string|null Full path if found, null if not found anywhere
     */
    private function resolvePortalFile(string $relativePath): ?string
    {
        $candidates = [
            $this->getPortalStoragePath() . $relativePath,                   // Current config path
            dirname(__DIR__, 4) . '/storage' . $relativePath,                // backend/storage fallback
            '/var/www/vps-email/storage' . $relativePath,                    // Production absolute
            '/var/www/vps-email/backend/storage' . $relativePath,            // Old production fallback
        ];

        // Also check the raw path if it's absolute (for documents with stored file_path)
        foreach (array_unique($candidates) as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        error_log("PortalController: File not found in any location for: $relativePath. Checked: " . implode(', ', array_unique($candidates)));
        return null;
    }

    /**
     * Log an action to the document audit trail
     */
    private function logDocumentAudit(\PDO $db, int $docId, string $action, string $actorType, ?string $actorEmail, Request $request, ?array $details = null): void
    {
        try {
            $stmt = $db->prepare('
                INSERT INTO portal_document_audit (document_id, action, actor_type, actor_email, ip_address, user_agent, details)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([
                $docId, $action, $actorType, $actorEmail,
                $request->getClientIp(), $request->getHeader('User-Agent'),
                $details ? json_encode($details) : null
            ]);
        } catch (\Throwable $e) {
            error_log("PortalController: Audit log error: " . $e->getMessage());
        }
    }
}

