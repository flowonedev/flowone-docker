<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\DriveService;
use Webmail\Services\DriveFileSharingService;
use Webmail\Services\OfficeEditorService;
use Webmail\Services\OfficeGuestLinkService;

/**
 * OfficeEditorController - REST API for the OnlyOffice integration.
 *
 * Authenticated endpoints (user JWT):
 *   GET    /office/status                      editor availability + server url
 *   GET    /office/files/{id}/config           signed editor config
 *   GET    /office/files/{id}/presence-token   collab-server JWT for the presence room
 *   POST   /office/files/new                   create blank docx/xlsx/pptx in Drive
 *   GET    /office/files/{id}/guest-links      list guest share links
 *   POST   /office/files/{id}/guest-links      create guest share link
 *   DELETE /office/guest-links/{token}         revoke guest share link
 *
 * Server-to-server endpoints (signed file token, called by the Document Server):
 *   GET    /office/files/{id}/content          file download
 *   POST   /office/files/{id}/callback         save callback
 *
 * Public guest endpoints (opaque guest token IS the auth):
 *   GET    /guest/office/{token}/config          signed editor config for guests
 *   GET    /guest/office/{token}/presence-token   collab-server JWT for live cursors
 */
class OfficeEditorController extends BaseController
{
    private OfficeEditorService $office;
    private DriveService $driveService;
    private ?OfficeGuestLinkService $guestLinks = null;

    public function __construct(array $config)
    {
        parent::__construct($config);
        $this->office = new OfficeEditorService($config);
        $this->driveService = new DriveService($config, $this->userEmail);
    }

    private function getGuestLinkService(): OfficeGuestLinkService
    {
        if (!$this->guestLinks) {
            $this->guestLinks = new OfficeGuestLinkService($this->config);
        }
        return $this->guestLinks;
    }

    // =========================================================================
    // Status
    // =========================================================================

    public function status(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $settings = $this->office->getSettings();
        return Response::success([
            'enabled' => $this->office->isEnabled(),
            'server_url' => $this->office->isEnabled() ? $settings['server_url'] : null,
            'editable_extensions' => OfficeEditorService::EDITABLE_EXTENSIONS,
        ]);
    }

    // =========================================================================
    // Editor config (authenticated users)
    // =========================================================================

    public function getConfig(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (!$this->office->isEnabled()) {
            return Response::error('Office editor is not configured', 503);
        }

        $email = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');

        $access = $this->resolveAccess($email, $fileId);
        if (!$access) {
            return Response::error('File not found or access denied', 404);
        }

        $file = $access['file'];
        if (!OfficeEditorService::isEditableFile($file)) {
            return Response::error('File type is not editable in the office editor', 400);
        }

        $lang = (string)($request->getQuery('lang') ?? 'en');
        $displayName = trim((string)($request->getQuery('name') ?? '')) ?: $email;

        // Record the open for the file's access history (who / when / how often).
        $this->driveService->logFileAccess(
            $fileId,
            $email,
            'open',
            $request->getClientIp(),
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        $config = $this->office->buildEditorConfig($file, $access['role'], [
            'id' => $email,
            'name' => $displayName,
        ], $lang);

        return Response::success([
            'editor_config' => $config,
            'server_url' => $this->office->getSettings()['server_url'],
            'file' => [
                'id' => (int)$file['id'],
                'name' => $file['original_name'],
                'folder_id' => $file['folder_id'] !== null ? (int)$file['folder_id'] : null,
                'extension' => OfficeEditorService::fileExtension($file),
            ],
            'role' => $access['role'],
            'is_owner' => $access['is_owner'],
        ]);
    }

    /**
     * Resolve what role a user has on a Drive file.
     * Owner -> editor. File collaborator (person/group share) or folder
     * collaborator -> editor/viewer per permission (highest wins).
     */
    private function resolveAccess(string $email, int $fileId): ?array
    {
        $email = strtolower($email);

        $owned = $this->driveService->getFile($email, $fileId);
        if ($owned) {
            if (!empty($owned['is_trashed'])) {
                return null;
            }
            return ['file' => $owned, 'role' => 'editor', 'is_owner' => true];
        }

        $fileSharing = new DriveFileSharingService($this->driveService->getDb());
        $direct = $fileSharing->resolveDirectFileAccess($email, $fileId);
        $folder = $this->driveService->hasFileCollaboratorAccess($email, $fileId);

        $permission = null;
        foreach ([$direct, $folder] as $access) {
            if (!$access) continue;
            $p = ($access['permission'] ?? 'viewer') === 'editor' ? 'editor' : 'viewer';
            if ($permission === null || $p === 'editor') {
                $permission = $p;
            }
        }

        if ($permission !== null) {
            $file = $this->driveService->getFileByIdWithPath($fileId);
            if (!$file || !empty($file['is_trashed'])) {
                return null;
            }
            return ['file' => $file, 'role' => $permission, 'is_owner' => false];
        }

        return null;
    }

    // =========================================================================
    // Presence token (Hocuspocus awareness room for live cursors / follow)
    // =========================================================================

    /**
     * Mint a collab-server JWT for the ephemeral "office-file-{id}" awareness
     * room. The Hocuspocus server treats these rooms as presence-only (no
     * document persistence) - they carry awareness states (cursors, names,
     * colors) for everyone who has the file open in the office editor.
     */
    public function presenceToken(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');

        $access = $this->resolveAccess($email, $fileId);
        if (!$access) {
            return Response::error('File not found or access denied', 404);
        }

        $displayName = trim((string)($request->getQuery('name') ?? ''));
        if ($displayName === '') {
            $displayName = explode('@', $email)[0];
        }

        $room = 'office-file-' . $fileId;
        $token = $this->mintPresenceJwt(strtolower($email), $displayName, $room, $access['role']);
        if ($token === null) {
            return Response::error('Collaboration signing key not configured', 503);
        }

        return Response::success([
            'token' => $token,
            'room' => $room,
            'role' => $access['role'],
            'email' => strtolower($email),
            'name' => $displayName,
            'expires_in' => 43200,
        ]);
    }

    /**
     * Public guest presence token (the opaque guest link token IS the auth).
     *
     * Lets a share-link guest join the same "office-file-{id}" awareness room
     * as authenticated users, so their live cursor is broadcast and they see
     * everyone else's. The guest link is validated WITHOUT being consumed
     * (the config request already counted the open), and the JWT is minted
     * under a stable guest identity that matches the editor config user id.
     */
    public function guestPresenceToken(Request $request): Response
    {
        if (!$this->office->isEnabled()) {
            return Response::error('Office editor is not configured', 503);
        }

        $token = (string)$request->getParam('token');
        $link = $this->getGuestLinkService()->validate($token);
        if (!$link) {
            return Response::error('This link is invalid, expired or revoked', 404);
        }

        $fileId = (int)$link['file_id'];
        $file = $this->driveService->getFileByIdWithPath($fileId);
        if (!$file || !empty($file['is_trashed'])) {
            return Response::error('File no longer exists', 404);
        }

        $guestName = trim((string)($request->getQuery('name') ?? ''));
        if ($guestName === '') {
            $guestName = 'Guest';
        }
        // Match guestConfig's "(guest)" suffix so the same person reads the
        // same way in both the native co-edit list and the presence cursor.
        $guestName = mb_substr($guestName, 0, 54) . ' (guest)';

        // Stable identity shared with the editor config's user.id.
        $guestId = 'guest-' . substr($token, 0, 12);
        $role = ($link['role'] ?? 'viewer') === 'editor' ? 'editor' : 'viewer';

        $room = 'office-file-' . $fileId;
        $jwt = $this->mintPresenceJwt($guestId, $guestName, $room, $role);
        if ($jwt === null) {
            return Response::error('Collaboration signing key not configured', 503);
        }

        return Response::success([
            'token' => $jwt,
            'room' => $room,
            'role' => $role,
            'email' => $guestId,
            'name' => $guestName,
            'expires_in' => 43200,
        ]);
    }

    /**
     * Mint a collab-server JWT for an ephemeral "office-file-{id}" awareness
     * room. Same signing scheme as the collab document tokens: RS256 private
     * key preferred, HS256 shared secret as fallback. Returns null when no
     * signing key is configured.
     */
    private function mintPresenceJwt(string $sub, string $name, string $room, string $role): ?string
    {
        $now = time();
        $payload = [
            'sub' => strtolower($sub),
            'name' => mb_substr($name, 0, 60),
            'documentId' => $room,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + 43200, // 12 hours
        ];

        $algorithm = $this->config['jwt']['algorithm'] ?? 'RS256';
        $signingKey = null;
        if ($algorithm === 'RS256') {
            $keyPath = $this->config['jwt']['private_key_path'] ?? '';
            if ($keyPath && file_exists($keyPath)) {
                $signingKey = file_get_contents($keyPath);
            } else {
                $algorithm = 'HS256';
            }
        }
        if ($signingKey === null) {
            $signingKey = $this->config['jwt']['secret'] ?? '';
        }
        if ($signingKey === '' || $signingKey === false) {
            return null;
        }

        return \Firebase\JWT\JWT::encode($payload, $signingKey, $algorithm);
    }

    // =========================================================================
    // Rename (live - updates the open editor's title too)
    // =========================================================================

    /**
     * PUT /office/files/{id}/name  { name }
     *
     * Renames the Drive file AND pushes the new title to the live editor
     * session so it updates in place (OnlyOffice otherwise keeps the title it
     * was opened with). The original extension is always preserved.
     */
    public function renameFile(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (!$this->office->isEnabled()) {
            return Response::error('Office editor is not configured', 503);
        }

        $email = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');

        $access = $this->resolveAccess($email, $fileId);
        if (!$access || $access['role'] !== 'editor') {
            return Response::error('Only editors can rename this file', 403);
        }

        $file = $access['file'];
        if (!OfficeEditorService::isEditableFile($file)) {
            return Response::error('File type is not editable in the office editor', 400);
        }

        // Strip any extension the client sent and re-apply the canonical one so
        // a rename can never change (or break) the document type.
        $newName = OfficeEditorService::normalizeRenameTarget(
            (string)($request->input('name') ?? ''),
            $file
        );
        if ($newName === '') {
            return Response::error('File name is required', 400);
        }

        // Shared editors don't own the row, so rename against the file owner.
        $ownerEmail = (string)($file['user_email'] ?? $email);
        if (!$this->driveService->renameFile($ownerEmail, $fileId, $newName)) {
            return Response::error('Rename failed', 500);
        }

        // Reflect it in the live session(s); best-effort, never fails the rename.
        $file['original_name'] = $newName;
        $this->office->updateDocumentTitle($file);

        return Response::success(['name' => $newName]);
    }

    // =========================================================================
    // New blank file
    // =========================================================================

    public function createFile(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (!$this->office->isEnabled()) {
            return Response::error('Office editor is not configured', 503);
        }

        $email = $this->getActiveEmail();
        $type = strtolower((string)$request->input('type'));
        $title = (string)($request->input('title') ?? '');
        $folderId = $request->input('folder_id') !== null ? (int)$request->input('folder_id') : null;

        if (!in_array($type, OfficeEditorService::CREATABLE_EXTENSIONS, true)) {
            return Response::error('Invalid file type. Use docx, xlsx or pptx.', 400);
        }

        $file = $this->office->createBlankFile($this->driveService, $email, $type, $title, $folderId);
        if (!$file) {
            return Response::error('Failed to create file (quota or storage error)', 500);
        }

        return Response::success(['file' => $file]);
    }

    // =========================================================================
    // Document Server: file download
    // =========================================================================

    public function content(Request $request): void
    {
        $fileId = (int)$request->getParam('id');
        $token = (string)($request->getQuery('token') ?? '');

        if (!$this->office->verifyFileToken($token, $fileId, 'content')) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid or expired token']);
            exit;
        }

        $file = $this->driveService->getFileByIdWithPath($fileId);
        if (!$file || empty($file['storage_path']) || !file_exists($file['storage_path'])) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'File not found']);
            exit;
        }

        // Always octet-stream: the web server gzip-compresses text/* mimes on
        // the fly (md, csv, txt), which would hand the Document Server the
        // compressed bytes as document content (mojibake). DS identifies the
        // format via the config fileType, not the mime type.
        $this->streamBinaryFile(
            $file['storage_path'],
            $file['original_name'],
            'application/octet-stream',
            (int)$file['size']
        );
        exit;
    }

    // =========================================================================
    // Document Server: save callback
    // =========================================================================

    public function callback(Request $request): Response
    {
        $fileId = (int)$request->getParam('id');
        $token = (string)($request->getQuery('token') ?? '');

        if (!$this->office->verifyFileToken($token, $fileId, 'callback')) {
            return Response::json(['error' => 1, 'message' => 'Invalid or expired token'], 403);
        }

        $raw = file_get_contents('php://input');
        $body = json_decode((string)$raw, true);
        if (!is_array($body)) {
            return Response::json(['error' => 1, 'message' => 'Invalid body'], 400);
        }

        // Defense in depth: the DS signs its callbacks with the shared secret.
        $trusted = $this->office->verifyCallbackJwt($body, $_SERVER['HTTP_AUTHORIZATION'] ?? null);
        if ($trusted === null) {
            error_log('[Office] Callback rejected: missing/invalid Document Server JWT');
            return Response::json(['error' => 1, 'message' => 'Invalid signature'], 403);
        }

        $result = $this->office->handleCallback($fileId, $trusted, $this->driveService);
        return Response::json($result);
    }

    // =========================================================================
    // Guest share links
    // =========================================================================

    public function listGuestLinks(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');

        $access = $this->resolveAccess($email, $fileId);
        if (!$access) {
            return Response::error('File not found or access denied', 404);
        }

        return Response::success(['links' => $this->getGuestLinkService()->listLinks($fileId)]);
    }

    public function createGuestLink(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (!$this->office->isEnabled()) {
            return Response::error('Office editor is not configured', 503);
        }

        $email = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');

        $access = $this->resolveAccess($email, $fileId);
        if (!$access || $access['role'] !== 'editor') {
            return Response::error('Only editors can create share links', 403);
        }

        $role = (string)($request->input('role') ?? 'viewer');
        $expiresInHours = $request->input('expires_in_hours') !== null
            ? (int)$request->input('expires_in_hours')
            : 7 * 24;
        $label = $request->input('label') !== null ? trim((string)$request->input('label')) : null;

        $link = $this->getGuestLinkService()->createLink($fileId, $role, $email, $expiresInHours ?: null, $label);

        $frontendUrl = rtrim($this->config['app']['frontend_url'] ?? 'https://flowone.pro', '/');
        $link['url'] = $frontendUrl . '/guest/office/' . $link['token'];

        return Response::success(['link' => $link]);
    }

    public function revokeGuestLink(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $email = $this->getActiveEmail();
        $token = (string)$request->getParam('token');

        if (!$this->getGuestLinkService()->revokeLink($token, $email)) {
            return Response::error('Link not found', 404);
        }
        return Response::success();
    }

    // =========================================================================
    // Guest editor config (public, token IS the auth)
    // =========================================================================

    public function guestConfig(Request $request): Response
    {
        if (!$this->office->isEnabled()) {
            return Response::error('Office editor is not configured', 503);
        }

        $token = (string)$request->getParam('token');
        $link = $this->getGuestLinkService()->validateAndConsume($token);
        if (!$link) {
            return Response::error('This link is invalid, expired or revoked', 404);
        }

        $fileId = (int)$link['file_id'];
        $file = $this->driveService->getFileByIdWithPath($fileId);
        if (!$file || !empty($file['is_trashed'])) {
            return Response::error('File no longer exists', 404);
        }
        if (!OfficeEditorService::isEditableFile($file)) {
            return Response::error('File type is not supported', 400);
        }

        $guestName = trim((string)($request->getQuery('name') ?? ''));
        if ($guestName === '') {
            $guestName = 'Guest';
        }
        $guestName = mb_substr($guestName, 0, 60);

        $lang = (string)($request->getQuery('lang') ?? 'en');
        $role = $link['role'] === 'editor' ? 'editor' : 'viewer';

        // Record the guest open for the file's access history.
        $this->driveService->logFileAccess(
            $fileId,
            mb_strtolower($guestName) . ' (guest)',
            'open',
            $request->getClientIp(),
            $_SERVER['HTTP_USER_AGENT'] ?? null
        );

        $config = $this->office->buildEditorConfig($file, $role, [
            'id' => 'guest-' . substr($token, 0, 12),
            'name' => $guestName . ' (guest)',
        ], $lang);

        // Guests don't get a "go back to Drive" button.
        unset($config['editorConfig']['customization']['goback']);
        $config['token'] = \Firebase\JWT\JWT::encode(
            array_diff_key($config, ['token' => true]),
            $this->office->getSettings()['jwt_secret'],
            'HS256'
        );

        return Response::success([
            'editor_config' => $config,
            'server_url' => $this->office->getSettings()['server_url'],
            'file' => [
                'name' => $file['original_name'],
                'extension' => OfficeEditorService::fileExtension($file),
            ],
            'role' => $role,
        ]);
    }
}
