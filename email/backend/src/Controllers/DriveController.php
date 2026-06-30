<?php

namespace Webmail\Controllers;

use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Services\DriveService;
use Webmail\Services\SmtpService;
use Webmail\Services\RedisCacheService;
use Webmail\Services\SearchIndexerService;
use function Webmail\Helpers\debug_log;

/**
 * DriveController - File storage REST API
 */
class DriveController extends BaseController
{
    private ?DriveService $driveService = null;
    private ?RedisCacheService $redisCache = null;
    private ?SearchIndexerService $searchIndexer = null;
    
    public function __construct(array $config)
    {
        parent::__construct($config);
        // Always initialize DriveService - needed for public share downloads too
        // Pass userEmail so Panel can determine storage based on user's domain
        $this->driveService = new DriveService($config, $this->userEmail);
        
        // Initialize Redis cache for thumbnails
        try {
            $this->redisCache = new RedisCacheService($config);
        } catch (\Throwable $e) {
            error_log('[DriveController] Redis not available: ' . $e->getMessage());
            $this->redisCache = null;
        }
    }
    
    // extractUserFromToken() and requireValidSession() replaced by BaseController::getUser() and requireAuth()
    
    /**
     * Get search indexer for indexing drive items
     */
    private function getSearchIndexer(): SearchIndexerService
    {
        if (!$this->searchIndexer) {
            $this->searchIndexer = new SearchIndexerService($this->config);
        }
        return $this->searchIndexer;
    }
    
    /**
     * Stream a file to the client and exit.
     *
     * Originally this used OLS's X-LiteSpeed-Location internal-redirect for
     * zero-copy serving, but that mechanism only works for paths inside the
     * vhost document root. Drive files live under /var/www/vps-email/storage/
     * which is OUTSIDE the docroot, so OLS rejected the redirect and returned
     * its own ~1 KB HTML error page. The browser then saved that error page
     * under the requested filename — the "broken 1 KB download" bug.
     *
     * We now stream directly from PHP. The cost (one worker held for the
     * download duration) is negligible compared to shipping garbage to users.
     *
     * Caller is responsible for sending Content-Type / Content-Disposition /
     * Content-Length BEFORE invoking this helper. This method exits, so any
     * code after the call is unreachable.
     */
    private function sendFileViaOls(string $filePath): void
    {
        // Defeat any server-side compression / proxy buffering. Binary data
        // must travel byte-for-byte; gzip-without-Content-Encoding corrupts it.
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Encoding: identity');
            header('X-Accel-Buffering: no');
        }

        set_time_limit(0);

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            // Last-ditch attempt; readfile() may still succeed on some FS errors.
            @readfile($filePath);
            exit;
        }

        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) break;
            echo $chunk;
            flush();
            if (connection_aborted()) break;
        }
        fclose($handle);
        exit;
    }
    
    /**
     * Index a file for search (call after upload)
     */
    private function triggerFileIndex(array $file, ?int $folderId = null): void
    {
        try {
            $email = $this->getActiveEmail();
            $folder = $folderId ? $this->driveService->getFolder($email, $folderId) : null;
            $this->getSearchIndexer()->indexDriveFile($email, $file, $folder);
        } catch (\Exception $e) {
            error_log("DriveController triggerFileIndex error: " . $e->getMessage());
        }
    }
    
    /**
     * Index a folder for search
     */
    private function triggerFolderIndex(array $folder): void
    {
        try {
            $this->getSearchIndexer()->indexDriveFolder($this->getActiveEmail(), $folder);
        } catch (\Exception $e) {
            error_log("DriveController triggerFolderIndex error: " . $e->getMessage());
        }
    }
    
    // getActiveEmail() and getSecondaryAccountEmail() inherited from BaseController
    
    /**
     * Get folder contents and quota info
     * Query params:
     *   - folder_id: optional folder ID
     *   - type: optional filter by type ('image', 'document', 'video', 'audio')
     */
    public function list(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $folderId = $request->getQuery('folder_id');
        $folderId = $folderId !== null && $folderId !== '' ? (int)$folderId : null;
        $typeFilter = $request->getQuery('type');
        
        $folders = $this->driveService->getFolders($activeEmail, $folderId);
        $files = $this->driveService->getFiles($activeEmail, $folderId);
        $quota = $this->driveService->getQuota($activeEmail);
        
        // Filter by type if specified
        if ($typeFilter) {
            $files = $this->filterFilesByType($files, $typeFilter, $activeEmail);
        }
        
        // Get current folder info and path
        $currentFolder = null;
        $path = [];
        if ($folderId) {
            $currentFolder = $this->driveService->getFolder($activeEmail, $folderId);
            $path = $this->driveService->getFolderPath($activeEmail, $folderId);
        }
        
        return Response::success([
            'folders' => $folders,
            'files' => $files,
            'current_folder' => $currentFolder,
            'path' => $path,
            'quota' => $quota,
        ]);
    }
    
    /**
     * Drive-wide search by name (partial match across all folders).
     * Returns folders/files in the same shape as list() so the grid renders them unchanged.
     */
    public function search(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $q = trim((string)$request->getQuery('q'));
        
        if (mb_strlen($q) < 2) {
            return Response::success(['folders' => [], 'files' => [], 'query' => $q]);
        }
        
        $result = $this->driveService->searchByName($activeEmail, $q);
        
        return Response::success([
            'folders' => $result['folders'],
            'files' => $result['files'],
            'query' => $q,
        ]);
    }
    
    /**
     * Filter files by type and recursively search all folders if no folder_id specified
     */
    private function filterFilesByType(array $files, string $type, string $email): array
    {
        $mimeTypePatterns = [
            'image' => 'image/',
            'document' => ['application/pdf', 'application/msword', 'application/vnd.', 'text/'],
            'video' => 'video/',
            'audio' => 'audio/',
        ];
        
        $pattern = $mimeTypePatterns[$type] ?? null;
        if (!$pattern) {
            return $files;
        }
        
        // Get all files from all folders if searching for images
        if ($type === 'image') {
            $files = $this->driveService->getAllFilesByMimeType($email, 'image/');
        }
        
        return array_filter($files, function($file) use ($pattern) {
            $mime = $file['mime_type'] ?? '';
            if (is_array($pattern)) {
                foreach ($pattern as $p) {
                    if (strpos($mime, $p) === 0) return true;
                }
                return false;
            }
            return strpos($mime, $pattern) === 0;
        });
    }
    
    /**
     * Create folder (or return existing if already exists)
     */
    public function createFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $name = $request->input('name');
        $parentId = $request->input('parent_id');
        $senderEmail = $request->input('sender_email'); // For client detection
        $clientId = $request->input('client_id'); // Direct client ID if already known
        
        if (empty($name)) {
            return Response::error('Folder name is required');
        }
        
        // If sender_email provided, try to find client
        $detectedClient = null;
        if ($senderEmail && !$clientId) {
            $detectedClient = $this->driveService->findClientByEmail($activeEmail, $senderEmail);
            if ($detectedClient) {
                $clientId = $detectedClient['id'];
            }
        }
        
        // Use findOrCreateFolder to handle "folder already exists" gracefully
        // This prevents 400 errors when syncing folders that already exist
        $folder = $this->driveService->findOrCreateFolder(
            $activeEmail, 
            $name, 
            $parentId ? (int)$parentId : null
        );
        
        if (!$folder) {
            return Response::error('Failed to create folder');
        }
        
        // If client detected and folder exists but doesn't have client_id, update it
        if ($clientId && (!isset($folder['client_id']) || !$folder['client_id'])) {
            $this->driveService->updateFolderClient($activeEmail, $folder['id'], $clientId);
            $folder['client_id'] = $clientId;
        }
        
        // Record sync event
        $this->createSyncEvent($activeEmail, 'folder_created', [
            'folder_id' => $folder['id'],
            'file_name' => $folder['name'],
            'source' => 'web'
        ]);
        
        // Index for search
        $this->triggerFolderIndex($folder);
        
        return Response::success([
            'folder' => $folder,
            'client' => $detectedClient
        ], 'Folder created');
    }
    
    /**
     * Find client by email address
     * GET /api/drive/find-client?email=sender@example.com
     */
    public function findClientByEmail(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $senderEmail = $request->getQuery('email');
        
        if (empty($senderEmail)) {
            return Response::error('Email parameter is required');
        }
        
        $client = $this->driveService->findClientByEmail($activeEmail, $senderEmail);
        
        return Response::success(['client' => $client]);
    }
    
    /**
     * Get or create folder for board files
     * Creates: Boards / [Board Name] /
     */
    public function getBoardFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $boardName = $request->input('board_name');
        
        if (empty($boardName)) {
            return Response::error('Board name is required');
        }
        
        $folder = $this->driveService->getOrCreateBoardFolder($activeEmail, $boardName);
        
        if (!$folder) {
            return Response::error('Failed to create board folder');
        }
        
        return Response::success(['folder' => $folder]);
    }
    
    /**
     * Rename folder
     */
    public function renameFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $name = $request->input('name');
        
        if (empty($name)) {
            return Response::error('Folder name is required');
        }
        
        if (!$this->driveService->renameFolder($activeEmail, $id, $name)) {
            return Response::error('Folder not found', 404);
        }
        
        return Response::success(null, 'Folder renamed');
    }
    
    /**
     * Update folder color
     */
    public function updateFolderColor(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $color = $request->input('color'); // Can be null to reset to auto
        
        // Validate color if provided (should be a valid color name)
        $validColors = ['amber', 'blue', 'green', 'purple', 'pink', 'red', 'orange', 'teal', 'slate', null];
        if ($color !== null && !in_array($color, $validColors)) {
            return Response::error('Invalid color');
        }
        
        if (!$this->driveService->updateFolderColor($activeEmail, $id, $color)) {
            return Response::error('Folder not found', 404);
        }
        
        // Return updated folder
        $folder = $this->driveService->getFolder($activeEmail, $id);
        
        return Response::success(['folder' => $folder], 'Folder color updated');
    }
    
    /**
     * Delete folder
     */
    public function deleteFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        
        $result = $this->driveService->deleteFolder($activeEmail, $id);
        
        // Check if result is an error message (string)
        if (is_string($result)) {
            return Response::error($result, 400);
        }
        
        if (!$result) {
            return Response::error('Folder not found', 404);
        }
        
        return Response::success(null, 'Folder deleted');
    }
    
    /**
     * Upload file
     */
    public function upload(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        // Debug: Check what we received
        debug_log("Drive upload - FILES: " . json_encode($_FILES));
        debug_log("Drive upload - POST: " . json_encode($_POST));
        debug_log("Drive upload - Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
        debug_log("Drive upload - Content-Length: " . ($_SERVER['CONTENT_LENGTH'] ?? 'not set'));
        
        if (empty($_FILES['file'])) {
            // Check for common issues
            $postMaxSize = ini_get('post_max_size');
            $uploadMaxFilesize = ini_get('upload_max_filesize');
            $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
            
            error_log("Drive upload failed - post_max_size: {$postMaxSize}, upload_max_filesize: {$uploadMaxFilesize}, content_length: {$contentLength}");
            
            return Response::error("No file uploaded. Check server limits: post_max_size={$postMaxSize}, upload_max_filesize={$uploadMaxFilesize}");
        }
        
        // Check for upload errors
        if (isset($_FILES['file']['error']) && $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize in php.ini',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE in form',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            ];
            $errorCode = $_FILES['file']['error'];
            $errorMsg = $errorMessages[$errorCode] ?? 'Unknown upload error';
            error_log("Drive upload error code {$errorCode}: {$errorMsg}");
            return Response::error($errorMsg);
        }
        
        $activeEmail = $this->getActiveEmail();
        $folderId = $request->input('folder_id');
        $source = $request->input('source') ?? 'web'; // Accept source from request (e.g., 'electron')
        
        // Check quota first
        $quota = $this->driveService->getQuota($activeEmail);
        $fileSize = $_FILES['file']['size'];
        
        if (!$quota['unlimited'] && $fileSize > $quota['available']) {
            return Response::error('Not enough storage space. Available: ' . DriveService::formatSize($quota['available']));
        }
        
        debug_log("Drive upload - Starting uploadFile for user: $activeEmail, folder: " . ($folderId ?? 'root'));
        
        try {
            $file = $this->driveService->uploadFile(
                $activeEmail,
                $_FILES['file'],
                $folderId ? (int)$folderId : null
            );
        } catch (\FlowOne\Storage\StorageBudgetExceededException $e) {
            // Phase 6b: system-wide budget pressure -> HTTP 503 with
            // Retry-After. Clients (esp. resumable uploaders) should
            // back off and try again rather than treating this as a
            // permanent failure.
            error_log("Drive upload refused by admission control for '{$_FILES['file']['name']}': " . $e->getMessage());
            return Response::error($e->getMessage(), 503)
                ->setHeader('Retry-After', (string) $e->retryAfterSec);
        } catch (\RuntimeException $e) {
            error_log("Drive upload failed for '{$_FILES['file']['name']}': " . $e->getMessage());
            return Response::error($e->getMessage());
        }
        
        if (!$file) {
            error_log("Drive upload - uploadFile returned null for: " . $_FILES['file']['name']);
            return Response::error('Failed to upload file - check server logs for details');
        }
        
        // Record sync event for activity log
        $this->createSyncEvent($activeEmail, 'file_created', [
            'file_id' => $file['id'],
            'folder_id' => $folderId ? (int)$folderId : null,
            'file_name' => $file['original_name'],
            'new_version' => 1,
            'source' => $source
        ]);
        
        // Search indexing is handled inside DriveService::uploadFileContent()
        // (which uploadFile() delegates to), so no explicit index call here.
        
        debug_log("Drive upload - Success! File ID: " . $file['id']);
        return Response::success(['file' => $file], 'File uploaded');
    }
    
    /**
     * Download file
     *
     * Three response modes:
     *   - 200 binary stream: file is ready (hot, or small cold file that
     *     was recalled inline by prepareForDownload).
     *   - 202 JSON {status:'restoring', retry_after:N}: file is large and
     *     cold; a background warmer has been spawned. Client should poll
     *     this endpoint after `retry_after` seconds. The frontend Drive
     *     UI surfaces this as a "Restoring from archive..." state.
     *   - 404 / 500 JSON: file missing or recall failed.
     */
    public function download(Request $request): Response
    {
        $id = (int)$request->getParam('id');

        // Native browser downloads cannot send Authorization headers, so they
        // authenticate with a short-lived signed token in the query string
        // (issued by downloadToken()). When present and valid we use the email
        // it was minted for; otherwise fall back to header-based auth.
        $activeEmail = null;
        $dlToken = $request->getQuery('dl_token');
        if (is_string($dlToken) && $dlToken !== '') {
            $activeEmail = $this->verifyDownloadToken($dlToken, $id);
        }
        if ($activeEmail === null) {
            $authError = $this->requireAuth($request);
            if ($authError) return $authError;
            $activeEmail = $this->getActiveEmail();
        }

        $prep = $this->driveService->prepareForDownload($activeEmail, $id);

        switch ($prep['status'] ?? '') {
            case 'restoring':
                $retry = (int) ($prep['retry_after'] ?? 5);
                return Response::json([
                    'success' => false,
                    'status' => 'restoring',
                    'retry_after' => $retry,
                    'message' => $prep['message']
                        ?? 'File is being restored from cold storage. Retry shortly.',
                ], 202)->setHeader('Retry-After', (string) $retry);

            case 'restore_failed':
                return Response::error(
                    $prep['message'] ?? 'Failed to restore file from cold storage',
                    503
                );

            case 'not_found':
                return Response::error($prep['message'] ?? 'File not found', 404);

            case 'ready':
                $file = $prep['file'];
                $path = $prep['path'];
                header('Content-Type: ' . $file['mime_type']);
                header($this->safeContentDisposition('attachment', $file['original_name']));
                header('Content-Length: ' . $file['size']);
                header('Cache-Control: private');
                $this->sendFileViaOls($path);
                // sendFileViaOls() exits the script; the line below is
                // never reached but keeps the static analyzer happy.
                return Response::success();

            default:
                return Response::serverError('Unexpected download preparation state');
        }
    }

    /**
     * Issue a short-lived signed token for a native browser download.
     *
     * The webmail download endpoint normally authenticates via the
     * Authorization header, which a plain <a download> / browser navigation
     * cannot send - that's why the old client buffered the whole file in RAM
     * via fetch()+blob() before the save dialog appeared (the "silent wait"
     * before a big download started). The client now asks for this token,
     * then points the browser straight at /download?dl_token=..., letting the
     * browser stream to disk with its native progress UI and no buffering.
     *
     * The cold-storage restore handshake lives here too, so the byte transfer
     * itself only ever runs against a file that's already hot.
     * GET /api/drive/files/{id}/download-token
     */
    public function downloadToken(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');

        $prep = $this->driveService->prepareForDownload($activeEmail, $id);

        switch ($prep['status'] ?? '') {
            case 'restoring':
                $retry = (int) ($prep['retry_after'] ?? 5);
                return Response::json([
                    'success' => false,
                    'status' => 'restoring',
                    'retry_after' => $retry,
                    'message' => $prep['message']
                        ?? 'File is being restored from cold storage. Retry shortly.',
                ], 202)->setHeader('Retry-After', (string) $retry);

            case 'restore_failed':
                return Response::error($prep['message'] ?? 'Failed to restore file from cold storage', 503);

            case 'not_found':
                return Response::error($prep['message'] ?? 'File not found', 404);

            case 'ready':
                return Response::success([
                    'token' => $this->makeDownloadToken($activeEmail, $id, 300),
                    'expires_in' => 300,
                ]);

            default:
                return Response::serverError('Unexpected download preparation state');
        }
    }

    /**
     * Secret used to sign ephemeral download tokens. Mirrors the resolution
     * order used elsewhere (NewsReaderController::signProxyUrl) so signing
     * works regardless of which key the environment provides.
     */
    private function downloadTokenSecret(): string
    {
        $candidates = [
            $this->config['jwt']['secret'] ?? null,
            $this->config['imap_encryption_key'] ?? null,
            $this->config['app_secret'] ?? null,
            getenv('JWT_SECRET') ?: null,
            getenv('IMAP_ENCRYPTION_KEY') ?: null,
            getenv('APP_SECRET') ?: null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                return $c;
            }
        }
        return hash('sha256', __FILE__);
    }

    /**
     * Mint a signed "{payload}.{sig}" download token scoped to one file/user.
     */
    private function makeDownloadToken(string $email, int $fileId, int $ttlSeconds = 300): string
    {
        $payload = rtrim(strtr(base64_encode((string) json_encode([
            'sub' => $email,
            'fid' => $fileId,
            'exp' => time() + max(30, $ttlSeconds),
        ])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->downloadTokenSecret(), true)), '+/', '-_'), '=');
        return $payload . '.' . $sig;
    }

    /**
     * Verify a download token against a file id. Returns the email it was
     * minted for, or null if the token is malformed, tampered, expired, or
     * for a different file.
     */
    private function verifyDownloadToken(string $token, int $fileId): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $sig] = $parts;
        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->downloadTokenSecret(), true)), '+/', '-_'), '=');
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        if ((int) ($data['fid'] ?? -1) !== $fileId) {
            return null;
        }
        if ((int) ($data['exp'] ?? 0) < time()) {
            return null;
        }
        $sub = $data['sub'] ?? null;
        return is_string($sub) && $sub !== '' ? $sub : null;
    }

    /**
     * TEST endpoint - download a simple test ZIP to verify binary streaming works
     * GET /api/drive/test-zip-download
     */
    public function testZipDownload(Request $request): void
    {
        // Disable compression
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');
        
        // Clear ALL output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        error_log("=== testZipDownload START ===");
        
        // Create a simple test ZIP in memory
        $tempFile = tempnam(sys_get_temp_dir(), 'test_zip_');
        $zip = new \ZipArchive();
        if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            error_log("testZipDownload: Failed to create test ZIP");
            http_response_code(500);
            echo "Failed to create test ZIP";
            exit;
        }
        
        // Add a simple text file to the ZIP
        $zip->addFromString('test.txt', 'This is a test file to verify ZIP downloads work correctly. Timestamp: ' . date('Y-m-d H:i:s'));
        $zip->close();
        
        $size = filesize($tempFile);
        error_log("testZipDownload: Created test ZIP, size=$size");
        
        // Verify ZIP signature
        $firstBytes = file_get_contents($tempFile, false, null, 0, 4);
        error_log("testZipDownload: ZIP signature=" . bin2hex($firstBytes));
        
        // Clear buffers again
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Send headers
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="test-download.zip"');
        header('Content-Length: ' . $size);
        header('Content-Transfer-Encoding: binary');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-store');
        
        // Send file
        $handle = fopen($tempFile, 'rb');
        $bytesSent = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) break;
            echo $chunk;
            $bytesSent += strlen($chunk);
            flush();
        }
        fclose($handle);
        
        error_log("testZipDownload: Sent $bytesSent bytes");
        
        @unlink($tempFile);
        exit;
    }
    
    /**
     * Download entire drive or a folder as zip (direct download - for small archives)
     */
    public function downloadZip(Request $request): void
    {
        // CRITICAL: Disable ALL compression and output buffering IMMEDIATELY
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        
        // Clear ALL output buffers FIRST
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $authError = $this->requireAuth($request);
        if ($authError) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        $activeEmail = $this->getActiveEmail();
        
        // Check for folder_id parameter
        $folderId = !empty($_GET['folder']) ? (int)$_GET['folder'] : null;
        
        $zipInfo = $this->driveService->createDriveZip($activeEmail, $folderId);
        
        if (!$zipInfo) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to create zip or no files available']);
            exit;
        }
        
        // Verify zip file exists and is readable
        if (!file_exists($zipInfo['path']) || !is_readable($zipInfo['path'])) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Zip file not accessible']);
            exit;
        }
        
        // Get actual file size
        $actualSize = filesize($zipInfo['path']);
        
        // Clear buffers again before binary output
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers to prevent ANY server modification
        header('Content-Type: application/octet-stream');
        header($this->safeContentDisposition('attachment', $zipInfo['filename']));
        header('Content-Length: ' . $actualSize);
        header('Content-Transfer-Encoding: binary');
        header('Accept-Ranges: none');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        set_time_limit(120);
        
        // Read and send file in chunks (safer than readfile for binary)
        $handle = fopen($zipInfo['path'], 'rb');
        if ($handle === false) {
            @unlink($zipInfo['path']);
            exit;
        }
        
        $bytesSent = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) break;
            echo $chunk;
            $bytesSent += strlen($chunk);
            // Flush output immediately
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
        
        fclose($handle);
        
        // Clean up temp file
        @unlink($zipInfo['path']);
        
        // CRITICAL: Exit immediately to prevent any further output
        exit;
    }
    
    /**
     * Create ZIP archive stored in Drive (with 1GB splitting for large folders)
     * POST /api/drive/create-archive
     * Body: { "folder_id": 123 } or { "folder_id": null } for entire drive
     * 
     * Returns JSON with created file(s) info - these can be shared like any other drive file
     */
    public function createArchive(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        // Set execution limits for long operations
        set_time_limit(0);
        ignore_user_abort(true);
        
        $activeEmail = $this->getActiveEmail();
        $folderId = $request->getBodyParam('folder_id');
        
        // Convert "null" string or empty to actual null
        if ($folderId === 'null' || $folderId === '' || $folderId === 0) {
            $folderId = null;
        } else {
            $folderId = (int)$folderId;
        }
        
        $result = $this->driveService->createDriveZipToDrive($activeEmail, $folderId);
        
        if (!$result['success']) {
            return Response::error($result['message'] ?? 'Failed to create archive');
        }
        
        return Response::success([
            'files' => $result['files'],
            'folder_id' => $result['folder_id'],
            'total_files' => $result['total_files'] ?? 0,
            'total_size' => $result['total_size'] ?? 0,
            'parts_count' => count($result['files'])
        ], $result['message']);
    }
    
    /**
     * Download selected files as zip
     */
    public function downloadFilesZip(Request $request): void
    {
        // CRITICAL: Disable ALL compression and output buffering IMMEDIATELY
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        
        // Clear ALL output buffers FIRST
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $authError = $this->requireAuth($request);
        if ($authError) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        $activeEmail = $this->getActiveEmail();
        
        // Get file IDs from query string or POST body
        $fileIds = [];
        if (!empty($_GET['files'])) {
            $fileIds = array_map('intval', explode(',', $_GET['files']));
        } elseif (!empty($_POST['files']) && is_array($_POST['files'])) {
            $fileIds = array_map('intval', $_POST['files']);
        } else {
            // Try JSON body
            $json = json_decode(file_get_contents('php://input'), true);
            if (!empty($json['files']) && is_array($json['files'])) {
                $fileIds = array_map('intval', $json['files']);
            }
        }
        
        if (empty($fileIds)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No files specified']);
            exit;
        }
        
        $zipInfo = $this->driveService->createFilesZip($activeEmail, $fileIds);
        
        if (!$zipInfo) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to create zip or no files available']);
            exit;
        }
        
        // Verify zip file exists
        if (!file_exists($zipInfo['path']) || !is_readable($zipInfo['path'])) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Zip file not accessible']);
            exit;
        }
        
        $actualSize = filesize($zipInfo['path']);
        
        // Clear buffers again before binary output
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers to prevent ANY server modification
        header('Content-Type: application/octet-stream');
        header($this->safeContentDisposition('attachment', $zipInfo['filename']));
        header('Content-Length: ' . $actualSize);
        header('Content-Transfer-Encoding: binary');
        header('Accept-Ranges: none');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        set_time_limit(120);
        
        // Read and send file in chunks (safer than readfile for binary)
        $handle = fopen($zipInfo['path'], 'rb');
        if ($handle === false) {
            @unlink($zipInfo['path']);
            exit;
        }
        
        $bytesSent = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) break;
            echo $chunk;
            $bytesSent += strlen($chunk);
            // Flush output immediately
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
        
        fclose($handle);
        
        // Clean up temp file
        @unlink($zipInfo['path']);
        
        // CRITICAL: Exit immediately to prevent any further output
        exit;
    }
    
    /**
     * Download selected files and folders as zip
     * GET /api/drive/download-selection-zip?files=1,2,3&folders=4,5,6
     */
    public function downloadSelectionZip(Request $request): void
    {
        // CRITICAL: Disable ALL compression and output buffering IMMEDIATELY
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        
        // Clear ALL output buffers FIRST - must be done before ANY other output
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $authError = $this->requireAuth($request);
        if ($authError) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        $activeEmail = $this->getActiveEmail();
        
        // Get file IDs and folder IDs from query string
        $fileIds = [];
        $folderIds = [];
        
        if (!empty($_GET['files'])) {
            $fileIds = array_filter(array_map('intval', explode(',', $_GET['files'])));
        }
        if (!empty($_GET['folders'])) {
            $folderIds = array_filter(array_map('intval', explode(',', $_GET['folders'])));
        }
        
        if (empty($fileIds) && empty($folderIds)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'No files or folders specified']);
            exit;
        }
        
        // Get debug session ID if provided
        $debugSessionId = !empty($_GET['debug_session']) ? trim($_GET['debug_session']) : null;
        
        // Log debug session ID for troubleshooting
        if ($debugSessionId) {
            error_log("downloadSelectionZip: Debug session ID = $debugSessionId");
            error_log("downloadSelectionZip: File IDs = " . implode(',', $fileIds));
            error_log("downloadSelectionZip: Folder IDs = " . implode(',', $folderIds));
        } else {
            error_log("downloadSelectionZip: No debug session ID provided");
        }
        
        $zipInfo = $this->driveService->createSelectionZip($activeEmail, $fileIds, $folderIds, $debugSessionId);
        
        // Log result
        if ($debugSessionId) {
            if ($zipInfo) {
                error_log("downloadSelectionZip: Zip created successfully, path = " . $zipInfo['path']);
            } else {
                error_log("downloadSelectionZip: Zip creation returned null");
            }
        }
        
        if (!$zipInfo) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to create zip or no files available']);
            exit;
        }
        
        // Verify zip file exists
        if (!file_exists($zipInfo['path']) || !is_readable($zipInfo['path'])) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Zip file not accessible']);
            exit;
        }
        
        $actualSize = filesize($zipInfo['path']);
        
        // Clear buffers again before binary output
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers to prevent ANY server modification
        header('Content-Type: application/octet-stream');
        header($this->safeContentDisposition('attachment', $zipInfo['filename']));
        header('Content-Length: ' . $actualSize);
        header('Content-Transfer-Encoding: binary');
        header('Accept-Ranges: none');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        set_time_limit(120);
        
        // Read and send file in chunks (safer than readfile for binary)
        $handle = fopen($zipInfo['path'], 'rb');
        if ($handle === false) {
            @unlink($zipInfo['path']);
            exit;
        }
        
        $bytesSent = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) break;
            echo $chunk;
            $bytesSent += strlen($chunk);
            // Flush output immediately
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
        
        fclose($handle);
        
        // Clean up temp file
        @unlink($zipInfo['path']);
        
        // CRITICAL: Exit immediately to prevent any further output
        exit;
    }
    
    /**
     * Get debug information for zip creation
     * GET /api/drive/zip-debug?session_id=xxx
     */
    public function getZipDebug(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        // Get session_id from query string (not route param)
        $sessionId = $request->getQuery('session_id') ?? '';
        
        if (empty($sessionId)) {
            return Response::error('Session ID required');
        }
        
        // Use app storage instead of sys_get_temp_dir() for consistent cross-request access
        $debugDir = __DIR__ . '/../../storage/cache/zip_debug';
        $debugFile = $debugDir . '/zip_debug_' . $sessionId . '.json';
        
        // Log for debugging
        error_log("getZipDebug: Looking for file: $debugFile");
        error_log("getZipDebug: File exists: " . (file_exists($debugFile) ? 'yes' : 'no'));
        error_log("getZipDebug: Debug dir: $debugDir");
        error_log("getZipDebug: Debug dir exists: " . (is_dir($debugDir) ? 'yes' : 'no'));
        
        if (file_exists($debugFile)) {
            $content = @file_get_contents($debugFile);
            if ($content !== false) {
                $debugData = json_decode($content, true);
                
                if ($debugData) {
                    error_log("getZipDebug: Found debug data, steps: " . count($debugData['steps'] ?? []));
                    return Response::success([
                        'session_id' => $sessionId,
                        'status' => $debugData['status'] ?? 'waiting',
                        'steps' => $debugData['steps'] ?? [],
                        'files' => $debugData['files'] ?? [],
                        'errors' => $debugData['errors'] ?? [],
                        'last_update' => $debugData['last_update'] ?? null
                    ]);
                } else {
                    error_log("getZipDebug: Failed to decode JSON from file");
                }
            } else {
                error_log("getZipDebug: Failed to read file");
            }
        } else {
            // List all debug files for troubleshooting
            $files = glob($debugDir . '/zip_debug_*.json');
            error_log("getZipDebug: Found " . count($files ?: []) . " debug files in debug dir");
            if ($files && count($files) > 0) {
                error_log("getZipDebug: Latest files: " . implode(', ', array_slice($files, -5)));
            }
        }
        
        return Response::success([
            'session_id' => $sessionId,
            'status' => 'waiting',
            'steps' => [],
            'files' => [],
            'errors' => []
        ]);
    }
    
    /**
     * Preview/thumbnail file (inline display)
     */
    public function preview(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $file = $this->driveService->getFile($activeEmail, $id);
        
        if (!$file) {
            return Response::error('File not found', 404);
        }
        
        $path = $this->driveService->getFilePath($activeEmail, $id);
        
        if (!$path) {
            return Response::error('File not found on disk', 404);
        }
        
        // Stream file inline (for images/videos)
        header('Content-Type: ' . $file['mime_type']);
        header($this->safeContentDisposition('inline', $file['original_name']));
        header('Content-Length: ' . $file['size']);
        header('Cache-Control: public, max-age=86400');
        
        $this->sendFileViaOls($path);
    }
    
    /**
     * Get thumbnail for an image file
     * GET /drive/files/{id}/thumbnail
     * 
     * Query params:
     * - size: max dimension (default 200, max 400)
     * 
     * Returns: Image data (JPEG) or 404
     * Uses Redis cache for generated thumbnails (24h TTL)
     */
    public function thumbnail(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $size = min(400, max(50, (int)$request->getQuery('size', 200)));
        
        $file = $this->driveService->getFile($activeEmail, $id);
        
        if (!$file) {
            return Response::error('File not found', 404);
        }
        
        // Check if it's an image
        $imageTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
        if (!in_array($file['mime_type'], $imageTypes)) {
            return Response::error('Not an image file', 400);
        }
        
        // AVIF needs special handling - we can't generate thumbnails for it with GD
        // So serve original if small enough, or return error
        if ($file['mime_type'] === 'image/avif') {
            $path = $this->driveService->getFilePath($activeEmail, $id);
            if (!$path || !file_exists($path)) {
                return Response::error('File not found on disk', 404);
            }
            // Serve original AVIF for small files, otherwise skip thumbnail
            if ($file['size'] < 500000) {
                header('Content-Type: image/avif');
                header('Content-Length: ' . $file['size']);
                header('Cache-Control: public, max-age=86400');
                $this->sendFileViaOls($path);
            }
            return Response::error('AVIF thumbnails not supported for large files', 400);
        }
        
        // Try Redis cache first
        $cacheKey = "{$id}:{$size}";
        if ($this->redisCache && $this->redisCache->isAvailable()) {
            $cached = $this->redisCache->getThumbnail($activeEmail, $id);
            if ($cached) {
                // Decode and send cached thumbnail
                $thumbData = base64_decode($cached);
                
                header('Content-Type: image/jpeg');
                header('Content-Length: ' . strlen($thumbData));
                header('Cache-Control: public, max-age=86400');
                header('X-Thumbnail-Cache: hit');
                
                echo $thumbData;
                exit;
            }
        }
        
        // Generate thumbnail
        $path = $this->driveService->getFilePath($activeEmail, $id);
        if (!$path || !file_exists($path)) {
            return Response::error('File not found on disk', 404);
        }
        
        $thumbData = $this->generateThumbnail($path, $file['mime_type'], $size);
        
        if (!$thumbData) {
            header('Content-Type: ' . $file['mime_type']);
            header('Content-Length: ' . $file['size']);
            header('Cache-Control: public, max-age=86400');
            $this->sendFileViaOls($path);
        }
        
        // Cache the thumbnail in Redis
        if ($this->redisCache && $this->redisCache->isAvailable()) {
            $this->redisCache->setThumbnail($activeEmail, $id, base64_encode($thumbData));
        }
        
        // Send thumbnail
        header('Content-Type: image/jpeg');
        header('Content-Length: ' . strlen($thumbData));
        header('Cache-Control: public, max-age=86400');
        header('X-Thumbnail-Cache: miss');
        
        echo $thumbData;
        exit;
    }
    
    /**
     * Generate a thumbnail for an image
     * Returns JPEG binary data or null on failure
     */
    private function generateThumbnail(string $path, string $mimeType, int $maxSize): ?string
    {
        try {
            // Create image resource based on type
            $image = null;
            switch ($mimeType) {
                case 'image/jpeg':
                    $image = @imagecreatefromjpeg($path);
                    break;
                case 'image/png':
                    $image = @imagecreatefrompng($path);
                    break;
                case 'image/gif':
                    $image = @imagecreatefromgif($path);
                    break;
                case 'image/webp':
                    $image = @imagecreatefromwebp($path);
                    break;
            }
            
            if (!$image) {
                error_log("[DriveController] Failed to create image from: $path");
                return null;
            }
            
            // Get original dimensions
            $origWidth = imagesx($image);
            $origHeight = imagesy($image);
            
            // Calculate new dimensions (maintain aspect ratio)
            if ($origWidth > $origHeight) {
                $newWidth = $maxSize;
                $newHeight = (int)($origHeight * ($maxSize / $origWidth));
            } else {
                $newHeight = $maxSize;
                $newWidth = (int)($origWidth * ($maxSize / $origHeight));
            }
            
            // Skip if image is already smaller than thumbnail size
            if ($origWidth <= $maxSize && $origHeight <= $maxSize) {
                $newWidth = $origWidth;
                $newHeight = $origHeight;
            }
            
            // Create thumbnail
            $thumb = imagecreatetruecolor($newWidth, $newHeight);
            
            // Preserve transparency for PNG/GIF
            if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
                imagealphablending($thumb, false);
                imagesavealpha($thumb, true);
                $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
                imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $transparent);
            } else {
                // White background for JPEG
                $white = imagecolorallocate($thumb, 255, 255, 255);
                imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $white);
            }
            
            // Resample
            imagecopyresampled(
                $thumb, $image,
                0, 0, 0, 0,
                $newWidth, $newHeight,
                $origWidth, $origHeight
            );
            
            // Output to string (JPEG for smaller size)
            ob_start();
            imagejpeg($thumb, null, 85);
            $data = ob_get_clean();
            
            // Clean up
            imagedestroy($image);
            imagedestroy($thumb);
            
            return $data;
            
        } catch (\Throwable $e) {
            error_log("[DriveController] Thumbnail generation error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get file info
     */
    public function getFile(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $file = $this->driveService->getFile($activeEmail, $id);
        
        if (!$file) {
            return Response::error('File not found', 404);
        }
        
        return Response::success(['file' => $file]);
    }
    
    /**
     * Delete file
     */
    public function deleteFile(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        
        if (!$this->driveService->deleteFile($activeEmail, $id)) {
            return Response::error('File not found', 404);
        }
        
        // Invalidate thumbnail cache
        if ($this->redisCache && $this->redisCache->isAvailable()) {
            $this->redisCache->invalidateThumbnail($activeEmail, $id);
        }
        
        return Response::success(null, 'File deleted');
    }
    
    /**
     * Rename file
     */
    public function renameFile(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $name = $request->input('name');
        
        if (empty($name)) {
            return Response::error('File name is required');
        }
        
        if (!$this->driveService->renameFile($activeEmail, $id, $name)) {
            return Response::error('File not found', 404);
        }
        
        return Response::success(null, 'File renamed');
    }
    
    /**
     * Move file to folder
     */
    public function moveFile(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $folderId = $request->input('folder_id');
        
        if (!$this->driveService->moveFile($activeEmail, $id, $folderId ? (int)$folderId : null)) {
            return Response::error('File not found', 404);
        }
        
        return Response::success(null, 'File moved');
    }
    
    /**
     * Move folder to another folder (change parent)
     */
    public function moveFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $parentId = $request->input('parent_id');
        
        // Prevent moving folder into itself or its descendants
        if ($parentId !== null && (int)$parentId === $id) {
            return Response::error('Cannot move folder into itself');
        }
        
        if (!$this->driveService->moveFolder($activeEmail, $id, $parentId !== null ? (int)$parentId : null)) {
            return Response::error('Folder not found or invalid move', 404);
        }
        
        return Response::success(null, 'Folder moved');
    }

    /**
     * Batched delete for many files + folders in a single HTTP call.
     *
     * Mirrors the single-item deleteFile / deleteFolder semantics but
     * collapses the per-item HTTP overhead and the per-item folder-size
     * walk into one pass. Folder deletes run BEFORE file deletes so
     * we don't try to update sizes for parents that are about to vanish.
     *
     * Body: { fileIds?: int[], folderIds?: int[] }
     * Returns: { success, failed, errors[], freedBytes }
     *
     * POST /drive/batch-delete
     */
    public function batchDelete(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $fileIds = (array)$request->input('fileIds', []);
        $folderIds = (array)$request->input('folderIds', []);

        // Hard cap at 100 combined items.
        if (count($fileIds) + count($folderIds) > 100) {
            $excess = count($fileIds) + count($folderIds) - 100;
            // Trim folders first, then files.
            while ($excess > 0 && !empty($folderIds)) {
                array_pop($folderIds);
                $excess--;
            }
            while ($excess > 0 && !empty($fileIds)) {
                array_pop($fileIds);
                $excess--;
            }
        }

        if (empty($fileIds) && empty($folderIds)) {
            return Response::error('At least one fileIds or folderIds entry is required', 400);
        }

        $totalSuccess = 0;
        $totalFailed = 0;
        $errors = [];
        $freedBytes = 0;
        $affectedFolders = [];

        // Folders first so we don't waste work updating sizes for files
        // whose parent folder is being removed in the same batch.
        if (!empty($folderIds)) {
            $r = $this->driveService->deleteManyFolders($activeEmail, $folderIds);
            $totalSuccess += $r['success'];
            $totalFailed += $r['failed'];
            $errors = array_merge($errors, $r['errors']);
            foreach ($r['affectedParents'] as $p) $affectedFolders[$p] = true;
        }

        if (!empty($fileIds)) {
            $r = $this->driveService->deleteManyFiles($activeEmail, $fileIds);
            $totalSuccess += $r['success'];
            $totalFailed += $r['failed'];
            $errors = array_merge($errors, $r['errors']);
            $freedBytes += $r['freedBytes'];
            foreach ($r['affectedFolders'] as $f) $affectedFolders[$f] = true;

            // Redis thumbnail cache invalidation per deleted file id.
            if ($this->redisCache && $this->redisCache->isAvailable()) {
                foreach ($fileIds as $fid) {
                    try {
                        $this->redisCache->invalidateThumbnail($activeEmail, (int)$fid);
                    } catch (\Throwable $e) {
                        // Non-critical
                    }
                }
            }
        }

        // ONE folder-size walk per unique affected folder, instead of N.
        foreach (array_keys($affectedFolders) as $folderId) {
            try {
                $this->driveService->updateFolderSizeWithParents($activeEmail, $folderId);
            } catch (\Throwable $e) {
                error_log("[DriveController::batchDelete] folder-size walk failed for {$folderId}: " . $e->getMessage());
            }
        }

        return Response::success([
            'success' => $totalSuccess,
            'failed' => $totalFailed,
            'errors' => $errors,
            'freedBytes' => $freedBytes,
        ], "{$totalSuccess} deleted, {$totalFailed} failed");
    }

    /**
     * Batched move for many files + folders to a single target folder.
     *
     * One UPDATE per kind (files / folders) instead of N. The
     * `updateFolderSizeWithParents` walk runs once per unique affected
     * folder (sources + target), not per item.
     *
     * Body: { fileIds?: int[], folderIds?: int[], targetFolderId: int|null }
     * Returns: { success, failed, errors[] }
     *
     * POST /drive/batch-move
     */
    public function batchMove(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $fileIds = (array)$request->input('fileIds', []);
        $folderIds = (array)$request->input('folderIds', []);
        $targetRaw = $request->input('targetFolderId');
        $targetFolderId = ($targetRaw === null || $targetRaw === '') ? null : (int)$targetRaw;

        if (count($fileIds) + count($folderIds) > 100) {
            $excess = count($fileIds) + count($folderIds) - 100;
            while ($excess > 0 && !empty($folderIds)) { array_pop($folderIds); $excess--; }
            while ($excess > 0 && !empty($fileIds)) { array_pop($fileIds); $excess--; }
        }

        if (empty($fileIds) && empty($folderIds)) {
            return Response::error('At least one fileIds or folderIds entry is required', 400);
        }

        $totalSuccess = 0;
        $totalFailed = 0;
        $errors = [];
        $affected = [];

        if (!empty($folderIds)) {
            $r = $this->driveService->moveManyFolders($activeEmail, $folderIds, $targetFolderId);
            $totalSuccess += $r['success'];
            $totalFailed += $r['failed'];
            $errors = array_merge($errors, $r['errors']);
            foreach ($r['affectedParents'] as $p) $affected[$p] = true;
        }

        if (!empty($fileIds)) {
            $r = $this->driveService->moveManyFiles($activeEmail, $fileIds, $targetFolderId);
            $totalSuccess += $r['success'];
            $totalFailed += $r['failed'];
            foreach ($r['affectedFolders'] as $f) $affected[$f] = true;
        }

        foreach (array_keys($affected) as $folderId) {
            try {
                $this->driveService->updateFolderSizeWithParents($activeEmail, $folderId);
            } catch (\Throwable $e) {
                error_log("[DriveController::batchMove] folder-size walk failed for {$folderId}: " . $e->getMessage());
            }
        }

        return Response::success([
            'success' => $totalSuccess,
            'failed' => $totalFailed,
            'errors' => $errors,
        ], "{$totalSuccess} moved, {$totalFailed} failed");
    }

    /**
     * Batched soft-delete (move to trash) for many files + folders in
     * one HTTP call. Mirrors the per-item trashFile / trashFolder
     * semantics but collapses N requests into 1 and runs the
     * folder-size walk ONCE per unique affected folder.
     *
     * Body: { fileIds?: int[], folderIds?: int[] }
     * Returns: { success, failed, errors[] }
     *
     * POST /drive/batch-trash
     */
    public function batchTrash(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $fileIds = (array)$request->input('fileIds', []);
        $folderIds = (array)$request->input('folderIds', []);

        if (count($fileIds) + count($folderIds) > 200) {
            $excess = count($fileIds) + count($folderIds) - 200;
            while ($excess > 0 && !empty($folderIds)) { array_pop($folderIds); $excess--; }
            while ($excess > 0 && !empty($fileIds)) { array_pop($fileIds); $excess--; }
        }

        if (empty($fileIds) && empty($folderIds)) {
            return Response::error('At least one fileIds or folderIds entry is required', 400);
        }

        $totalSuccess = 0;
        $totalFailed = 0;
        $errors = [];
        $affected = [];

        // Folders first so file-level size updates don't fight with
        // parents that are about to be hidden.
        if (!empty($folderIds)) {
            $r = $this->driveService->trashManyFolders($activeEmail, $folderIds);
            $totalSuccess += $r['success'];
            $totalFailed += $r['failed'];
            $errors = array_merge($errors, $r['errors']);
            foreach ($r['affectedParents'] as $p) $affected[$p] = true;
        }

        if (!empty($fileIds)) {
            $r = $this->driveService->trashManyFiles($activeEmail, $fileIds);
            $totalSuccess += $r['success'];
            $totalFailed += $r['failed'];
            foreach ($r['affectedFolders'] as $f) $affected[$f] = true;
        }

        // ONE folder-size walk per unique affected folder.
        foreach (array_keys($affected) as $folderId) {
            try {
                $this->driveService->updateFolderSizeWithParents($activeEmail, $folderId);
            } catch (\Throwable $e) {
                error_log("[DriveController::batchTrash] folder-size walk failed for {$folderId}: " . $e->getMessage());
            }
        }

        return Response::success([
            'success' => $totalSuccess,
            'failed' => $totalFailed,
            'errors' => $errors,
        ], "{$totalSuccess} moved to trash, {$totalFailed} failed");
    }

    /**
     * Batched restore-from-trash for many files + folders in one HTTP
     * call.
     *
     * Body: { fileIds?: int[], folderIds?: int[] }
     * Returns: { success, failed }
     *
     * POST /drive/batch-restore
     */
    public function batchRestore(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $fileIds = (array)$request->input('fileIds', []);
        $folderIds = (array)$request->input('folderIds', []);

        if (count($fileIds) + count($folderIds) > 200) {
            $excess = count($fileIds) + count($folderIds) - 200;
            while ($excess > 0 && !empty($folderIds)) { array_pop($folderIds); $excess--; }
            while ($excess > 0 && !empty($fileIds)) { array_pop($fileIds); $excess--; }
        }

        if (empty($fileIds) && empty($folderIds)) {
            return Response::error('At least one fileIds or folderIds entry is required', 400);
        }

        $totalSuccess = 0;
        $totalFailed = 0;
        $affected = [];

        if (!empty($folderIds)) {
            $r = $this->driveService->restoreManyFolders($activeEmail, $folderIds);
            $totalSuccess += $r['success'];
            $totalFailed += $r['failed'];
            foreach ($r['affectedParents'] as $p) $affected[$p] = true;
        }

        if (!empty($fileIds)) {
            $r = $this->driveService->restoreManyFiles($activeEmail, $fileIds);
            $totalSuccess += $r['success'];
            $totalFailed += $r['failed'];
            foreach ($r['affectedFolders'] as $f) $affected[$f] = true;
        }

        foreach (array_keys($affected) as $folderId) {
            try {
                $this->driveService->updateFolderSizeWithParents($activeEmail, $folderId);
            } catch (\Throwable $e) {
                error_log("[DriveController::batchRestore] folder-size walk failed for {$folderId}: " . $e->getMessage());
            }
        }

        return Response::success([
            'success' => $totalSuccess,
            'failed' => $totalFailed,
        ], "{$totalSuccess} restored, {$totalFailed} failed");
    }

    /**
     * Copy file to a folder
     */
    public function copyFile(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $folderId = $request->input('folder_id');

        $copy = $this->driveService->copyFile($activeEmail, $id, $folderId !== null ? (int)$folderId : null);
        if (!$copy) {
            return Response::error('Failed to copy file', 500);
        }

        return Response::success(['file' => $copy], 'File copied');
    }

    /**
     * Copy folder to another parent
     */
    public function copyFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $parentId = $request->input('parent_id');

        if ($parentId !== null && (int)$parentId === $id) {
            return Response::error('Cannot copy folder into itself');
        }

        $newFolderId = $this->driveService->copyFolder($activeEmail, $id, $parentId !== null ? (int)$parentId : null);
        if (!$newFolderId) {
            return Response::error('Failed to copy folder', 500);
        }

        return Response::success(['folder_id' => $newFolderId], 'Folder copied');
    }
    
    /**
     * Create share link
     */
    public function share(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $expiresHours = $request->input('expires_hours'); // null = never expires
        $isEmailAttachment = (bool)$request->input('is_email_attachment', false); // If true, auto-delete when expired
        $maxDownloads = $request->input('max_downloads'); // null = unlimited
        $password = $request->input('password'); // null = no password
        
        $token = $this->driveService->createShareLink(
            $activeEmail, 
            $id, 
            $expiresHours ? (int)$expiresHours : null,
            $isEmailAttachment,
            $maxDownloads ? (int)$maxDownloads : null,
            $password
        );
        
        if (!$token) {
            return Response::error('File not found', 404);
        }
        
        // Build share URL - include filename as query param for download fallback
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
        $shareUrl = $baseUrl . '/api/drive/share/' . $token;
        
        // Get original filename to embed in the URL
        $file = $this->driveService->getFile($activeEmail, $id);
        $originalName = $file['original_name'] ?? '';
        if (!empty($originalName)) {
            $shareUrl .= '?fn=' . rawurlencode($originalName);
        }
        
        return Response::success([
            'token' => $token,
            'url' => $shareUrl,
            'filename' => $originalName,
            'max_downloads' => $maxDownloads ? (int)$maxDownloads : null,
            'has_password' => !empty($password)
        ], 'Share link created');
    }
    
    /**
     * Update share link settings
     * PUT /api/drive/files/{id}/share
     */
    public function updateShare(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $expiresHours = $request->input('expires_hours');
        $maxDownloads = $request->input('max_downloads');
        $password = $request->input('password'); // Empty string clears password
        $resetDownloadCount = (bool)$request->input('reset_download_count', false);
        
        $success = $this->driveService->updateShareLink(
            $activeEmail,
            $id,
            $expiresHours !== null ? (int)$expiresHours : null,
            $maxDownloads !== null ? (int)$maxDownloads : null,
            $password,
            $resetDownloadCount
        );
        
        if (!$success) {
            return Response::error('Share link not found', 404);
        }
        
        return Response::success(null, 'Share link updated');
    }
    
    /**
     * Get share info for a public file (no auth required)
     * GET /api/drive/share/{token}/info
     */
    public function getShareInfo(Request $request): Response
    {
        $token = $request->getParam('token');
        
        $info = $this->driveService->getFileShareInfo($token);
        
        if (!$info) {
            return Response::error('Share link not found or expired', 404);
        }
        
        return Response::success($info);
    }
    
    /**
     * Validate share password (no auth required)
     * POST /api/drive/share/{token}/validate
     */
    public function validateSharePassword(Request $request): Response
    {
        $token = $request->getParam('token');
        $password = $request->input('password');
        
        if (!$this->driveService->validateFileSharePassword($token, $password ?? '')) {
            return Response::error('Invalid password', 403);
        }
        
        return Response::success(null, 'Password valid');
    }
    
    /**
     * Cleanup expired email attachments
     * Requires auth - only logged-in users can trigger via API.
     * For automated cleanup, use the cron: php /var/www/vps-email/backend/cron/cleanup-drive.php
     */
    public function cleanup(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $deletedCount = $this->driveService->cleanupExpiredEmailAttachments();
        
        return Response::success([
            'deleted_count' => $deletedCount,
        ], "Cleaned up $deletedCount expired email attachments");
    }
    
    /**
     * Remove share link
     */
    public function unshare(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        
        if (!$this->driveService->removeShareLink($activeEmail, $id)) {
            return Response::error('File not found', 404);
        }
        
        return Response::success(null, 'Share link removed');
    }
    
    /**
     * Create folder share link
     */
    public function shareFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        $expiresHours = $request->input('expires_hours'); // null = never expires
        $maxDownloads = $request->input('max_downloads'); // null = unlimited
        $password = $request->input('password'); // null = no password
        
        error_log("shareFolder: Creating share link for folder $id, email $activeEmail, expires=$expiresHours, maxDownloads=$maxDownloads");
        
        $token = $this->driveService->createFolderShareLink(
            $activeEmail, 
            $id, 
            $expiresHours ? (int)$expiresHours : null,
            $maxDownloads ? (int)$maxDownloads : null,
            $password
        );
        
        error_log("shareFolder: Token returned: " . ($token ? substr($token, 0, 16) . '...' : 'NULL'));
        
        if (!$token) {
            return Response::error('Folder not found', 404);
        }
        
        // Build share URL
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
        $shareUrl = $baseUrl . '/share/folder/' . $token;
        
        return Response::success([
            'token' => $token,
            'url' => $shareUrl,
            'max_downloads' => $maxDownloads ? (int)$maxDownloads : null,
            'has_password' => !empty($password)
        ], 'Folder share link created');
    }
    
    /**
     * Remove folder share link
     */
    public function unshareFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        
        if (!$this->driveService->removeFolderShareLink($activeEmail, $id)) {
            return Response::error('Folder not found', 404);
        }
        
        return Response::success(null, 'Folder share link removed');
    }

    /**
     * Get the current public-link state for a file (owner only).
     * GET /api/drive/files/{id}/share
     *
     * Lets the unified share modal self-hydrate when opened from a context
     * that doesn't carry the file's share_token (office editor, attachment
     * preview). Returns the full state so the frontend never has to guess.
     */
    public function getShare(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');

        $file = $this->driveService->getFile($activeEmail, $id);
        if (!$file) {
            return Response::error('File not found', 404);
        }

        return Response::success($this->buildFileShareState($file));
    }

    /**
     * Get the current public-link state for a folder (owner only).
     * GET /api/drive/folders/{id}/share
     */
    public function getFolderShare(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');

        $folder = $this->driveService->getFolder($activeEmail, $id);
        if (!$folder) {
            return Response::error('Folder not found', 404);
        }

        return Response::success($this->buildFolderShareState($folder));
    }

    /**
     * Notify internal colleagues/groups about a file's public share link.
     * POST /api/drive/files/{id}/share/notify  { user_ids: [], group_ids: [] }
     */
    public function notifyShare(Request $request): Response
    {
        return $this->handleShareNotify($request, 'file');
    }

    /**
     * Notify internal colleagues/groups about a folder's public share link.
     * POST /api/drive/folders/{id}/share/notify  { user_ids: [], group_ids: [] }
     */
    public function notifyFolderShare(Request $request): Response
    {
        return $this->handleShareNotify($request, 'folder');
    }

    /**
     * Build the public-link state payload for a file row.
     */
    private function buildFileShareState(array $file): array
    {
        $token = $file['share_token'] ?? null;
        $isShared = !empty($token);
        $url = null;
        if ($isShared) {
            $baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
            $url = $baseUrl . '/api/drive/share/' . $token;
            if (!empty($file['original_name'])) {
                $url .= '?fn=' . rawurlencode($file['original_name']);
            }
        }

        return [
            'is_shared' => $isShared,
            'token' => $token,
            'url' => $url,
            'expires' => $file['share_expires'] ?? null,
            'max_downloads' => isset($file['max_downloads']) && $file['max_downloads'] !== null ? (int)$file['max_downloads'] : null,
            'download_count' => isset($file['download_count']) ? (int)$file['download_count'] : 0,
            'has_password' => !empty($file['share_password']),
        ];
    }

    /**
     * Build the public-link state payload for a folder row.
     */
    private function buildFolderShareState(array $folder): array
    {
        $token = $folder['share_token'] ?? null;
        $isShared = !empty($token);
        $url = null;
        if ($isShared) {
            $baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
            $url = $baseUrl . '/share/folder/' . $token;
        }

        return [
            'is_shared' => $isShared,
            'token' => $token,
            'url' => $url,
            'expires' => $folder['share_expires'] ?? null,
            'max_downloads' => isset($folder['max_downloads']) && $folder['max_downloads'] !== null ? (int)$folder['max_downloads'] : null,
            'download_count' => isset($folder['download_count']) ? (int)$folder['download_count'] : 0,
            'has_password' => !empty($folder['share_password']),
        ];
    }

    /**
     * Shared notify handler for files and folders.
     * Resolves recipient ids to emails server-side, requires an active share
     * link, and delivers an in-app notification to each recipient.
     */
    private function handleShareNotify(Request $request, string $targetType): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');

        $userIds = $request->input('user_ids', []);
        $groupIds = $request->input('group_ids', []);
        $userIds = is_array($userIds) ? array_values(array_filter(array_map('intval', $userIds))) : [];
        $groupIds = is_array($groupIds) ? array_values(array_filter(array_map('intval', $groupIds))) : [];

        if (empty($userIds) && empty($groupIds)) {
            return Response::error('No recipients selected', 400);
        }

        if ($targetType === 'folder') {
            $item = $this->driveService->getFolder($activeEmail, $id);
            $state = $item ? $this->buildFolderShareState($item) : null;
            $itemName = $item['name'] ?? '';
        } else {
            $item = $this->driveService->getFile($activeEmail, $id);
            $state = $item ? $this->buildFileShareState($item) : null;
            $itemName = $item['original_name'] ?? ($item['filename'] ?? '');
        }

        if (!$item) {
            return Response::error(($targetType === 'folder' ? 'Folder' : 'File') . ' not found', 404);
        }
        if (empty($state['is_shared'])) {
            return Response::error('Create a share link first', 400);
        }

        $notifier = new \Webmail\Services\ShareNotificationService($this->config);
        $recipients = $notifier->resolveRecipientEmails($activeEmail, $userIds, $groupIds);
        if (empty($recipients)) {
            return Response::error('No valid recipients', 400);
        }

        $sent = $notifier->notify($activeEmail, $itemName, $state['url'], $recipients, $targetType);

        return Response::success(['sent' => $sent], 'Notification sent');
    }

    /**
     * Public folder share view (no auth required)
     * Returns folder contents for public viewing
     */
    public function publicFolderView(Request $request): Response
    {
        $token = $request->getParam('token');
        error_log("publicFolderView: Received token: " . ($token ? substr($token, 0, 16) . '...' : 'EMPTY'));
        
        if (empty($token)) {
            return Response::error('Invalid link', 400);
        }
        
        // First check if the share exists and get its status
        $shareInfo = $this->driveService->getFolderShareInfo($token);
        
        // Check if download limit reached FIRST (before password check)
        if ($shareInfo && $shareInfo['limit_reached']) {
            return Response::error('Maximum download limit reached', 403, [
                'limit_reached' => true, 
                'folder_name' => $shareInfo['name'] ?? 'Shared Folder',
                'max_downloads' => $shareInfo['max_downloads'],
                'download_count' => $shareInfo['download_count']
            ]);
        }
        
        // Check if password is required
        $requiresPassword = $this->driveService->folderShareRequiresPassword($token);
        error_log("publicFolderView: Requires password: " . ($requiresPassword ? 'YES' : 'NO'));
        
        if ($requiresPassword) {
            $password = $request->input('password') ?? $_GET['p'] ?? $_SERVER['HTTP_X_SHARE_PASSWORD'] ?? '';
            if (!$this->driveService->validateFolderSharePassword($token, $password)) {
                // Return info that password is needed
                return Response::error('Password required', 403, ['requires_password' => true, 'folder_name' => $shareInfo['name'] ?? 'Shared Folder']);
            }
        }
        
        $folderData = $this->driveService->getFolderByShareToken($token);
        error_log("publicFolderView: Folder data: " . ($folderData ? 'FOUND' : 'NULL'));
        
        if (!$folderData) {
            return Response::error('Folder not found or link expired', 404);
        }
        
        // Get share info for display (expiration, download limits, etc)
        $shareInfo = $this->driveService->getFolderShareInfo($token);
        
        return Response::success([
            'folder' => $folderData['folder'],
            'files' => $folderData['files'],
            'subfolders' => $folderData['subfolders'],
            'share_info' => $shareInfo
        ]);
    }
    
    /**
     * Public file download from shared folder (no auth required)
     */
    public function publicFolderFileDownload(Request $request): Response
    {
        $token = $request->getParam('token');
        $fileId = (int)$request->getParam('file_id');
        
        if (empty($token)) {
            return Response::error('Invalid link', 400);
        }
        
        // Check download limit FIRST
        if (!$this->driveService->canDownloadFromFolder($token)) {
            return Response::error('Download limit reached', 403, ['limit_reached' => true]);
        }
        
        // Check if password is required
        if ($this->driveService->folderShareRequiresPassword($token)) {
            $password = $_GET['p'] ?? $_SERVER['HTTP_X_SHARE_PASSWORD'] ?? '';
            if (!$this->driveService->validateFolderSharePassword($token, $password)) {
                return Response::error('Password required', 403, ['requires_password' => true]);
            }
        }
        
        $fileInfo = $this->driveService->getFileFromSharedFolder($token, $fileId);
        
        if (!$fileInfo) {
            return Response::error('File not found or access denied', 404);
        }
        
        // Increment folder download count BEFORE streaming
        $this->driveService->incrementFolderDownloadCount($token);
        
        // Stream file
        header('Content-Type: ' . $fileInfo['mime_type']);
        header($this->safeContentDisposition('attachment', $fileInfo['filename']));
        header('Content-Length: ' . $fileInfo['size']);
        header('Cache-Control: private');
        
        $this->sendFileViaOls($fileInfo['path']);
    }
    
    /**
     * Navigate into a subfolder within a shared folder (no auth required)
     */
    public function publicSubfolderView(Request $request): Response
    {
        $token = $request->getParam('token');
        $subfolderId = (int)$request->getParam('subfolder_id');
        
        if (empty($token)) {
            return Response::error('Invalid link', 400);
        }

        if ($this->driveService->folderShareRequiresPassword($token)) {
            $password = $request->input('password') ?? $_GET['p'] ?? $_SERVER['HTTP_X_SHARE_PASSWORD'] ?? '';
            if (!$this->driveService->validateFolderSharePassword($token, $password)) {
                return Response::error('Password required', 403, ['requires_password' => true]);
            }
        }
        
        $folderData = $this->driveService->getSubfolderFromSharedFolder($token, $subfolderId);
        
        if (!$folderData) {
            return Response::error('Folder not found or access denied', 404);
        }
        
        return Response::success([
            'folder' => $folderData['folder'],
            'files' => $folderData['files'],
            'subfolders' => $folderData['subfolders'],
            'path' => $folderData['path'],
            'shared_folder_id' => $folderData['shared_folder_id'],
        ]);
    }
    
    /**
     * Download shared folder as zip (no auth required)
     * Supports downloading all files or specific file IDs
     */
    public function publicFolderZipDownload(Request $request): void
    {
        // CRITICAL: Disable ALL compression and output buffering IMMEDIATELY
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');
        @ini_set('output_buffering', 'Off');
        
        // Clear ALL output buffers FIRST
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $token = $request->getParam('token');
        
        if (empty($token)) {
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid link']);
            exit;
        }
        
        // Check download limit
        if (!$this->driveService->canDownloadFromFolder($token)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Download limit reached', 'limit_reached' => true]);
            exit;
        }
        
        // Check if password is required
        if ($this->driveService->folderShareRequiresPassword($token)) {
            $password = $_GET['p'] ?? $_POST['p'] ?? $_SERVER['HTTP_X_SHARE_PASSWORD'] ?? '';
            if (!$this->driveService->validateFolderSharePassword($token, $password)) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Password required', 'requires_password' => true]);
                exit;
            }
        }
        
        // Get optional file IDs from query or POST
        $fileIds = null;
        if (!empty($_GET['files'])) {
            $fileIds = array_map('intval', explode(',', $_GET['files']));
        } elseif (!empty($_POST['files']) && is_array($_POST['files'])) {
            $fileIds = array_map('intval', $_POST['files']);
        }
        
        // Get optional subfolder ID
        $subfolderId = !empty($_GET['subfolder']) ? (int)$_GET['subfolder'] : null;
        
        // Create the zip
        $zipInfo = $this->driveService->createSharedFolderZip($token, $fileIds, $subfolderId);
        
        if (!$zipInfo) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to create zip or no files available']);
            exit;
        }
        
        // Increment download count
        $this->driveService->incrementFolderDownloadCount($token);
        
        // Clear buffers again before binary output
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Set headers to prevent ANY server modification
        header('Content-Type: application/octet-stream');
        header($this->safeContentDisposition('attachment', $zipInfo['filename']));
        header('Content-Length: ' . $zipInfo['size']);
        header('Content-Transfer-Encoding: binary');
        header('Accept-Ranges: none');
        header('X-Content-Type-Options: nosniff');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        set_time_limit(120);
        
        // Read and send file in chunks (safer than readfile for binary)
        $handle = fopen($zipInfo['path'], 'rb');
        if ($handle === false) {
            @unlink($zipInfo['path']);
            exit;
        }
        
        $bytesSent = 0;
        while (!feof($handle)) {
            $chunk = fread($handle, 8192);
            if ($chunk === false) break;
            echo $chunk;
            $bytesSent += strlen($chunk);
            // Flush output immediately
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
        
        fclose($handle);
        
        // Clean up temp file
        @unlink($zipInfo['path']);
        
        // CRITICAL: Exit immediately to prevent any further output
        exit;
    }
    
    /**
     * Public share download (no auth required)
     * This method streams the file directly, so it doesn't return a Response object
     */
    public function publicDownload(Request $request): void
    {
        $token = $request->getParam('token');
        
        if (empty($token)) {
            error_log("DriveController publicDownload: Empty token");
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid link']);
            exit;
        }
        
        // Check if password is required
        if ($this->driveService->shareRequiresPassword($token)) {
            // For password-protected files, require password in header or query param
            $password = $_GET['p'] ?? $_SERVER['HTTP_X_SHARE_PASSWORD'] ?? '';
            if (!$this->driveService->validateFileSharePassword($token, $password)) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Password required', 'requires_password' => true]);
                exit;
            }
        }
        
        error_log("DriveController publicDownload: Token: $token");
        $fileInfo = $this->driveService->getFilePathByToken($token);
        
        if (!$fileInfo) {
            error_log("DriveController publicDownload: File not found for token: $token");
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'File not found or link expired']);
            exit;
        }

        // View-only restriction: when the owner disabled downloads, public-link
        // recipients (always viewers) may still preview images / PDFs inline,
        // but cannot download the file as an attachment.
        $tokenFile = $this->driveService->getFileByShareToken($token);
        $restrictions = $tokenFile ? $this->driveService->getFileRestrictions((int)$tokenFile['id']) : null;
        if ($tokenFile && $restrictions && !empty($restrictions['no_download'])) {
            $mime = $fileInfo['mime_type'] ?? '';
            $isImagePreview = strpos($mime, 'image/') === 0;
            $wantsInlinePreview = !empty($_GET['preview']) && in_array($mime, [
                'application/pdf', 'text/plain', 'text/html', 'text/csv', 'application/json',
            ], true);
            if (!$isImagePreview && !$wantsInlinePreview) {
                $this->driveService->logFileAccess(
                    (int)$tokenFile['id'], null, 'download_blocked',
                    $request->getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? null
                );
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Downloading is disabled for this file']);
                exit;
            }
        }
        
        // Increment download count
        $this->driveService->incrementFileDownloadCount($token);
        
        // Determine the filename: prefer ?fn= query param, fallback to DB original_name
        // The ?fn= param is explicitly set by compose when creating share URLs
        $fnParam = $_GET['fn'] ?? '';
        if (!empty($fnParam)) {
            $filename = basename($fnParam); // Sanitize: only keep the filename part
        } else {
            $filename = $fileInfo['filename'] ?? 'download';
        }
        
        $diskSize = @filesize($fileInfo['path']);
        error_log("DriveController publicDownload: path={$fileInfo['path']}, mime={$fileInfo['mime_type']}, db_size={$fileInfo['size']}, disk_size={$diskSize}, filename={$filename}");
        
        // For images, serve inline so they can be displayed in <img> tags
        // For PDFs/documents with ?preview=1, serve inline for in-app viewing
        // For other files, serve as attachment (ALWAYS force download for non-images)
        $isImage = strpos($fileInfo['mime_type'], 'image/') === 0;
        $wantsPreview = !empty($_GET['preview']);
        $isPreviewable = in_array($fileInfo['mime_type'], [
            'application/pdf',
            'text/plain',
            'text/html',
            'text/csv',
            'application/json',
        ], true);
        $disposition = ($isImage || ($wantsPreview && $isPreviewable)) ? 'inline' : 'attachment';
        
        error_log("DriveController publicDownload: Serving as $disposition (isImage: " . ($isImage ? 'true' : 'false') . ")");
        
        // Clean any previous output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }

        $actualSize = filesize($fileInfo['path']);
        if ($actualSize === false) {
            error_log("DriveController publicDownload: filesize() failed for {$fileInfo['path']}");
            $actualSize = $fileInfo['size'];
        }

        header('Content-Type: ' . $fileInfo['mime_type']);
        header($this->safeContentDisposition($disposition, $filename));
        header('Content-Length: ' . $actualSize);
        header('Cache-Control: public, max-age=86400');
        header('X-Content-Type-Options: nosniff');

        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowedOrigins = ['https://flowone.pro'];
        if (in_array($origin, $allowedOrigins, true) || preg_match('#^https?://localhost(:\d+)?$#', $origin)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }

        $this->sendFileViaOls($fileInfo['path']);
    }
    
    /**
     * Save email attachment to Drive
     * POST /api/drive/save-attachment
     * Creates Attachments/YYYY-MM-DD - Subject/ folder structure
     */
    public function saveAttachment(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $filename = $request->input('filename');
        $content = $request->input('content'); // Base64 encoded
        $mimeType = $request->input('mime_type', 'application/octet-stream');
        $emailSubject = $request->input('email_subject', 'Email');
        $emailDate = $request->input('email_date');
        $senderEmail = $request->input('sender_email'); // For client folder detection
        // Source IMAP metadata: lets the email view show a persistent
        // "Saved to Drive" indicator on the original attachment card.
        $sourceFolder = $request->input('source_folder');
        $sourceUidRaw = $request->input('source_uid');
        $sourceUid = ($sourceUidRaw !== null && $sourceUidRaw !== '') ? (int)$sourceUidRaw : null;
        $sourcePart = $request->input('source_part');

        // Normalize folder casing to match the email view's lookup so
        // the saved-status indicator finds the row on subsequent loads.
        if (is_string($sourceFolder) && $sourceFolder !== '') {
            if (stripos($sourceFolder, 'inbox.') === 0 && substr($sourceFolder, 0, 6) !== 'INBOX.') {
                $sourceFolder = 'INBOX.' . substr($sourceFolder, 6);
            } elseif (strcasecmp($sourceFolder, 'inbox') === 0) {
                $sourceFolder = 'INBOX';
            }
        }

        if (empty($filename) || empty($content)) {
            return Response::error('Filename and content are required');
        }
        
        // Decode base64 content
        $decodedContent = base64_decode($content);
        if ($decodedContent === false) {
            return Response::error('Invalid base64 content');
        }
        
        $result = $this->driveService->saveEmailAttachment(
            $activeEmail,
            $filename,
            $decodedContent,
            $mimeType,
            $emailSubject,
            $emailDate,
            $senderEmail,
            $sourceFolder ?: null,
            $sourceUid,
            $sourcePart ?: null
        );
        
        if (!$result) {
            return Response::error('Failed to save attachment - check storage quota');
        }
        
        return Response::success([
            'file' => $result['file'],
            'folder' => $result['folder'],
            'attachments_folder' => $result['attachments_folder'],
            'client_folder' => $result['client_folder'] ?? null
        ], 'Attachment saved to Drive');
    }

    /**
     * Look up Drive files saved from a specific IMAP message.
     * GET /api/drive/email-attachments-status?folder=INBOX&uid=12345
     *
     * Used by the email view to render a persistent "Saved to Drive"
     * indicator + Share action on attachment cards. Returns a list of
     * saved files with the IMAP `part` they originated from so the
     * frontend can match them to the in-memory attachment array.
     */
    public function emailAttachmentsStatus(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $folder = (string)$request->input('folder', '');
        $uid = (int)$request->input('uid', 0);
        // Optional: list of attachments in the IMAP message. If passed,
        // unmatched parts get a filename+size fallback lookup against
        // the user's Drive (with self-healing backfill of source_email_*
        // columns). Older clients can omit this and still get the
        // precise-only response for back-compat.
        $rawAttachments = $request->input('attachments');
        $attachments = [];
        if (is_array($rawAttachments)) {
            foreach ($rawAttachments as $a) {
                if (!is_array($a)) continue;
                $attachments[] = [
                    'part' => isset($a['part']) ? (string)$a['part'] : null,
                    'filename' => $a['filename'] ?? null,
                    'size' => isset($a['size']) ? (int)$a['size'] : null,
                ];
            }
        }

        if ($folder === '' || $uid <= 0) {
            return Response::error('folder and uid are required', 400);
        }

        // Normalize folder casing so the lookup matches what
        // MailboxController::normalizeFolderName stored at save time.
        // Mirrors the lightweight branches there (we deliberately skip
        // the IMAP findActualFolderName roundtrip since the DriveService
        // is not authenticated to the user's mailbox here).
        if (stripos($folder, 'inbox.') === 0 && substr($folder, 0, 6) !== 'INBOX.') {
            $folder = 'INBOX.' . substr($folder, 6);
        } elseif (strcasecmp($folder, 'inbox') === 0) {
            $folder = 'INBOX';
        }

        $files = !empty($attachments)
            ? $this->driveService->resolveSavedFilesForEmailMessage($activeEmail, $folder, $uid, $attachments)
            : $this->driveService->getEmailAttachmentSavedFiles($activeEmail, $folder, $uid);

        // Build a public share URL for files that already have a token,
        // so the frontend can copy without an extra round trip. Files
        // without a token will get one on demand via the existing
        // /drive/files/{id}/share endpoint.
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
        $items = array_map(function ($file) use ($baseUrl) {
            $shareUrl = null;
            if (!empty($file['share_token'])) {
                $shareUrl = $baseUrl . '/api/drive/share/' . $file['share_token'];
                if (!empty($file['filename'])) {
                    $shareUrl .= '?fn=' . rawurlencode($file['filename']);
                }
            }
            $file['share_url'] = $shareUrl;
            return $file;
        }, $files);

        return Response::success([
            'folder' => $folder,
            'uid' => $uid,
            'files' => $items,
        ]);
    }

    /**
     * Get quota info
     */
    public function quota(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $quota = $this->driveService->getQuota($activeEmail);
        
        return Response::success([
            'quota' => $quota,
            'formatted' => [
                'quota' => $quota['unlimited'] ? 'Unlimited' : DriveService::formatSize($quota['quota']),
                'used' => DriveService::formatSize($quota['used']),
                'available' => $quota['unlimited'] ? 'Unlimited' : DriveService::formatSize($quota['available']),
            ],
        ]);
    }
    
    /**
     * Get all folders (for tree view)
     */
    public function allFolders(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $folders = $this->driveService->getAllFolders($activeEmail);
        
        return Response::success(['folders' => $folders]);
    }
    
    /**
     * Recalculate all folder sizes
     * POST /api/drive/recalculate-sizes
     */
    public function recalculateFolderSizes(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $this->driveService->recalculateAllFolderSizes($activeEmail);
        
        return Response::success(null, 'Folder sizes recalculated');
    }
    
    // ===== TRASH OPERATIONS =====
    
    /**
     * Get trashed items
     * GET /api/drive/trash
     */
    public function listTrash(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $trashedItems = $this->driveService->getTrashedItems($activeEmail);
        
        return Response::success($trashedItems);
    }
    
    /**
     * Move file to trash (soft delete)
     * POST /api/drive/files/{id}/trash
     */
    public function trashFile(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        
        // Get file info before trashing
        $file = $this->driveService->getFile($activeEmail, $id);
        
        if (!$this->driveService->trashFile($activeEmail, $id)) {
            return Response::error('File not found', 404);
        }
        
        // Record sync event
        if ($file) {
            $this->createSyncEvent($activeEmail, 'file_deleted', [
                'file_id' => $id,
                'folder_id' => $file['folder_id'] ?? null,
                'file_name' => $file['original_name'] ?? 'Unknown',
                'source' => 'web'
            ]);
        }
        
        return Response::success(null, 'File moved to trash');
    }
    
    /**
     * Move folder to trash (soft delete)
     * POST /api/drive/folders/{id}/trash
     */
    public function trashFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        
        // Get folder info first
        $folder = $this->driveService->getFolder($activeEmail, $id);
        
        // trashFolder now handles all protection checks (system folders + board-linked)
        $result = $this->driveService->trashFolder($activeEmail, $id);
        if (is_string($result)) {
            return Response::error($result, 403);
        }
        if (!$result) {
            return Response::error('Folder not found', 404);
        }
        
        // Record sync event
        if ($folder) {
            $this->createSyncEvent($activeEmail, 'folder_deleted', [
                'folder_id' => $id,
                'file_name' => $folder['name'] ?? 'Unknown',
                'source' => 'web'
            ]);
        }
        
        return Response::success(null, 'Folder moved to trash');
    }
    
    /**
     * Restore file from trash
     * POST /api/drive/files/{id}/restore
     */
    public function restoreFile(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        
        if (!$this->driveService->restoreFile($activeEmail, $id)) {
            return Response::error('File not found in trash', 404);
        }
        
        return Response::success(null, 'File restored');
    }
    
    /**
     * Restore folder from trash
     * POST /api/drive/folders/{id}/restore
     */
    public function restoreFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        
        if (!$this->driveService->restoreFolder($activeEmail, $id)) {
            return Response::error('Folder not found in trash', 404);
        }
        
        return Response::success(null, 'Folder restored');
    }
    
    /**
     * Empty trash - permanently delete all trashed items
     * DELETE /api/drive/trash
     */
    public function emptyTrash(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $deletedCount = $this->driveService->emptyTrash($activeEmail);
        
        return Response::success(['deleted_count' => $deletedCount], "Deleted $deletedCount items permanently");
    }
    
    /**
     * Permanently delete a single item from trash
     * DELETE /api/drive/trash/{type}/{id}
     */
    public function permanentlyDelete(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $type = $request->getParam('type');
        $id = (int)$request->getParam('id');
        
        if ($type === 'file') {
            if (!$this->driveService->permanentlyDeleteFile($activeEmail, $id)) {
                return Response::error('File not found', 404);
            }
        } elseif ($type === 'folder') {
            if (!$this->driveService->permanentlyDeleteFolder($activeEmail, $id)) {
                return Response::error('Folder not found', 404);
            }
        } else {
            return Response::error('Invalid type', 400);
        }
        
        return Response::success(null, 'Item permanently deleted');
    }
    
    // ===== FILE VERSIONING =====
    
    /**
     * Upload file with versioning support
     * If file with same name exists, creates new version
     * POST /api/drive/upload-versioned
     */
    public function uploadVersioned(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        // Empty $_FILES means the multipart body never reached PHP intact - almost
        // always because the POST exceeded post_max_size (PHP silently discards the
        // whole body). Surface the real limits so the cause is obvious (a generic
        // "No file uploaded" sent users chasing the wrong thing on large photos).
        if (empty($_FILES['file'])) {
            // Decisive diagnostics for "No file uploaded": Content-Length tells us
            // whether the body even arrived (0/tiny => client never sent the file;
            // large => the body arrived but the multipart wasn't parsed). The keys
            // of $_POST/$_FILES tell us whether only the file part went missing
            // (e.g. an iOS/proxy/SW issue stripping the body) vs the whole body.
            $postMaxSize = ini_get('post_max_size');
            $uploadMaxFilesize = ini_get('upload_max_filesize');
            $contentLength = $_SERVER['CONTENT_LENGTH'] ?? 0;
            $contentType = $_SERVER['CONTENT_TYPE'] ?? 'not set';
            $postKeys = implode(',', array_keys($_POST)) ?: 'none';
            $filesKeys = implode(',', array_keys($_FILES)) ?: 'none';
            error_log(
                "Drive upload-versioned NO-FILE - content_length: {$contentLength}, content_type: {$contentType}, " .
                "post_keys: [{$postKeys}], files_keys: [{$filesKeys}], " .
                "post_max_size: {$postMaxSize}, upload_max_filesize: {$uploadMaxFilesize}"
            );
            return Response::error("No file uploaded. The file may not have reached the server (limits: post_max_size={$postMaxSize}, upload_max_filesize={$uploadMaxFilesize}).");
        }
        
        // File metadata is present but PHP flagged an upload error (e.g. the single
        // file exceeded upload_max_filesize). Return a specific, actionable message.
        if (isset($_FILES['file']['error']) && $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize in php.ini',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE in form',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            ];
            $errorCode = $_FILES['file']['error'];
            $errorMsg = $errorMessages[$errorCode] ?? 'Unknown upload error';
            error_log("Drive upload-versioned error code {$errorCode}: {$errorMsg}");
            return Response::error($errorMsg);
        }
        
        $activeEmail = $this->getActiveEmail();
        $folderId = $request->input('folder_id');
        $source = $request->input('source') ?? 'web'; // Accept source from request (e.g., 'electron')
        
        // Check quota first
        $quota = $this->driveService->getQuota($activeEmail);
        $fileSize = $_FILES['file']['size'];
        
        if (!$quota['unlimited'] && $fileSize > $quota['available']) {
            return Response::error('Not enough storage space. Available: ' . DriveService::formatSize($quota['available']));
        }
        
        try {
            $file = $this->driveService->uploadFileWithVersioning(
                $activeEmail,
                $_FILES['file'],
                $folderId ? (int)$folderId : null
            );
        } catch (\FlowOne\Storage\StorageBudgetExceededException $e) {
            error_log("Drive upload-versioned refused by admission control for '{$_FILES['file']['name']}': " . $e->getMessage());
            return Response::error($e->getMessage(), 503)
                ->setHeader('Retry-After', (string) $e->retryAfterSec);
        } catch (\RuntimeException $e) {
            error_log("Drive upload-versioned failed for '{$_FILES['file']['name']}': " . $e->getMessage());
            return Response::error($e->getMessage());
        }
        
        if (!$file) {
            error_log("Drive upload-versioned returned no file for '{$_FILES['file']['name']}' (no exception thrown)");
            return Response::error('Upload could not be completed - the file was not saved');
        }
        
        // Record sync event for activity log
        $isNewFile = ($file['current_version'] ?? 1) === 1;
        $this->createSyncEvent($activeEmail, $isNewFile ? 'file_created' : 'file_updated', [
            'file_id' => $file['id'],
            'folder_id' => $folderId ? (int)$folderId : null,
            'file_name' => $file['original_name'],
            'new_version' => $file['current_version'] ?? 1,
            'source' => $source
        ]);
        
        return Response::success(['file' => $file], 'File uploaded');
    }

    /**
     * Receive one chunk of a chunked/resumable upload. The full file body
     * cannot exceed ~2GB across LSAPI, so large files are sliced client-side
     * and reassembled here. On the final chunk the upload is committed exactly
     * like uploadVersioned().
     * POST /api/drive/upload-chunk
     */
    public function uploadChunk(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        if (empty($_FILES['chunk']) || empty($_FILES['chunk']['tmp_name'])) {
            // A missing chunk almost always means the individual chunk exceeded the
            // server's per-request limits. Report them so a too-small chunk size on
            // the client (relative to upload_max_filesize / post_max_size) is visible.
            $postMaxSize = ini_get('post_max_size');
            $uploadMaxFilesize = ini_get('upload_max_filesize');
            $chunkErr = $_FILES['chunk']['error'] ?? 'absent';
            error_log("Drive upload-chunk failed - post_max_size: {$postMaxSize}, upload_max_filesize: {$uploadMaxFilesize}, chunk_error: {$chunkErr}");
            return Response::error("No chunk uploaded. The chunk may exceed the server limit (post_max_size={$postMaxSize}, upload_max_filesize={$uploadMaxFilesize}).");
        }

        $activeEmail = $this->getActiveEmail();
        $uploadId    = (string)($request->input('upload_id') ?? '');
        $chunkIndex  = (int)($request->input('chunk_index') ?? -1);
        $totalChunks = (int)($request->input('total_chunks') ?? 0);
        $chunkSize   = (int)($request->input('chunk_size') ?? 0);
        $fileName    = (string)($request->input('file_name') ?? '');
        $fileSize    = (int)($request->input('file_size') ?? 0);
        $folderId    = $request->input('folder_id');
        $source      = $request->input('source') ?? 'web';

        if ($uploadId === '' || $chunkSize <= 0 || $totalChunks <= 0 || $fileName === '' || $fileSize <= 0) {
            return Response::error('Invalid chunk metadata');
        }
        if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            return Response::error('Invalid chunk index');
        }

        // Quota pre-check on the announced total size (fast fail before assembling).
        $quota = $this->driveService->getQuota($activeEmail);
        if (!$quota['unlimited'] && $fileSize > $quota['available']) {
            return Response::error('Not enough storage space. Available: ' . DriveService::formatSize($quota['available']));
        }

        try {
            $received = $this->driveService->appendUploadChunk(
                $activeEmail,
                $uploadId,
                $chunkIndex,
                $chunkSize,
                $_FILES['chunk']['tmp_name']
            );
        } catch (\RuntimeException $e) {
            error_log("Drive upload-chunk append failed (upload_id={$uploadId}, idx={$chunkIndex}): " . $e->getMessage());
            return Response::error($e->getMessage());
        }

        // Not the last chunk: acknowledge receipt so the client sends the next.
        if ($chunkIndex < $totalChunks - 1) {
            return Response::success(['received' => $received, 'completed' => false], 'Chunk received');
        }

        // Final chunk: commit the assembled file.
        try {
            $file = $this->driveService->finalizeChunkedUpload(
                $activeEmail,
                $uploadId,
                $fileName,
                $fileSize,
                $folderId ? (int)$folderId : null
            );
        } catch (\FlowOne\Storage\StorageBudgetExceededException $e) {
            error_log("Drive upload-chunk refused by admission control for '{$fileName}': " . $e->getMessage());
            return Response::error($e->getMessage(), 503)
                ->setHeader('Retry-After', (string) $e->retryAfterSec);
        } catch (\RuntimeException $e) {
            error_log("Drive upload-chunk finalize failed for '{$fileName}': " . $e->getMessage());
            return Response::error($e->getMessage());
        }

        if (!$file) {
            return Response::error('Failed to finalize upload');
        }

        $isNewFile = ($file['current_version'] ?? 1) === 1;
        $this->createSyncEvent($activeEmail, $isNewFile ? 'file_created' : 'file_updated', [
            'file_id'     => $file['id'],
            'folder_id'   => $folderId ? (int)$folderId : null,
            'file_name'   => $file['original_name'],
            'new_version' => $file['current_version'] ?? 1,
            'source'      => $source
        ]);

        return Response::success(['file' => $file, 'completed' => true], 'File uploaded');
    }

    /**
     * Report how many bytes have already been assembled for a chunked upload,
     * so the client can resume after an interruption.
     * GET /api/drive/upload-chunk/status?upload_id=...
     */
    public function uploadChunkStatus(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $uploadId = (string)($request->getQuery('upload_id') ?? '');
        if ($uploadId === '') {
            return Response::error('Missing upload_id');
        }

        try {
            $received = $this->driveService->getChunkUploadStatus($activeEmail, $uploadId);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage());
        }

        return Response::success(['received' => $received]);
    }

    // Version endpoints (list/restore/delete/download/preview/pin/label/
    // usage/cleanup/content-commit) live in DriveVersionsController.
    
    // ===== ACTIVITY TRACKING =====
    
    /**
     * Record file access
     * POST /api/drive/files/{id}/access
     */
    public function recordAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        
        $this->driveService->recordFileAccess($activeEmail, $id);
        $this->driveService->logFileAccess(
            $id, $activeEmail, 'open',
            $request->getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? null
        );
        
        return Response::success(null, 'Access recorded');
    }

    /**
     * Record folder access (powers the Recent view for folders).
     * POST /api/drive/folders/{id}/access
     */
    public function recordFolderAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');

        $this->driveService->recordFolderAccess($activeEmail, $id);

        return Response::success(null, 'Folder access recorded');
    }

    /**
     * Toggle the starred flag on a file or folder.
     * POST /api/drive/{type}/{id}/star
     */
    public function toggleStar(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $type = $request->getParam('type');
        $id = (int)$request->getParam('id');

        if ($type === 'file' || $type === 'files') {
            $newState = $this->driveService->toggleStarFile($activeEmail, $id);
            if ($newState === null) return Response::error('File not found', 404);
            return Response::success(['is_starred' => $newState, 'type' => 'file', 'id' => $id]);
        }
        if ($type === 'folder' || $type === 'folders') {
            $newState = $this->driveService->toggleStarFolder($activeEmail, $id);
            if ($newState === null) return Response::error('Folder not found', 404);
            return Response::success(['is_starred' => $newState, 'type' => 'folder', 'id' => $id]);
        }

        return Response::error('Invalid type (expected "file" or "folder")', 400);
    }

    /**
     * List starred files + folders for the current user.
     * GET /api/drive/starred
     */
    public function listStarred(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $items = $this->driveService->getStarredItems($activeEmail);

        return Response::success($items);
    }

    /**
     * List recently accessed files + folders for the current user.
     * GET /api/drive/recent?limit=50
     */
    public function listRecent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $limit = (int)($request->getQuery('limit') ?? 50);
        $items = $this->driveService->getRecentItems($activeEmail, $limit);

        return Response::success($items);
    }

    /**
     * Get file with detailed information
     * GET /api/drive/files/{id}/details
     */
    public function getFileDetails(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');
        
        $file = $this->driveService->getFileWithDetails($activeEmail, $id);
        
        if (!$file) {
            return Response::error('File not found', 404);
        }
        
        return Response::success(['file' => $file]);
    }

    /**
     * Get the view-only restriction flags for a file (owner only).
     * GET /api/drive/files/{id}/restrictions
     */
    public function getRestrictions(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');

        $file = $this->driveService->getFile($activeEmail, $id);
        if (!$file) {
            return Response::error('File not found', 404);
        }

        return Response::success([
            'no_download' => (bool)($file['no_download'] ?? false),
            'no_print' => (bool)($file['no_print'] ?? false),
        ]);
    }

    /**
     * Update the view-only restriction flags for a file (owner only).
     * These apply to recipients with VIEW access; editors are unaffected.
     * PATCH /api/drive/files/{id}/restrictions
     * Body: { no_download?: bool, no_print?: bool }
     */
    public function updateRestrictions(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');

        $file = $this->driveService->getFile($activeEmail, $id);
        if (!$file) {
            return Response::error('File not found', 404);
        }

        $noDownload = (bool)$request->input('no_download', (bool)($file['no_download'] ?? false));
        $noPrint = (bool)$request->input('no_print', (bool)($file['no_print'] ?? false));

        if (!$this->driveService->setFileRestrictions($activeEmail, $id, $noDownload, $noPrint)) {
            return Response::error('Failed to update restrictions', 500);
        }

        return Response::success([
            'no_download' => $noDownload,
            'no_print' => $noPrint,
        ], 'Restrictions updated');
    }

    /**
     * Get the open history (who / when / how many times) for a file (owner only).
     * GET /api/drive/files/{id}/access-log
     */
    public function getAccessLog(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $id = (int)$request->getParam('id');

        $file = $this->driveService->getFile($activeEmail, $id);
        if (!$file) {
            return Response::error('File not found', 404);
        }

        return Response::success([
            'entries' => $this->driveService->getFileAccessLog($id),
        ]);
    }
    
    // ===== FOLDER COLLABORATORS =====
    
    /**
     * Add collaborator to a folder
     * POST /api/drive/folders/{id}/collaborators
     */
    public function addCollaborator(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $folderId = (int)$request->getParam('id');
        $collaboratorEmail = $request->input('email');
        $permission = $request->input('permission', 'viewer');
        
        if (empty($collaboratorEmail) || !filter_var($collaboratorEmail, FILTER_VALIDATE_EMAIL)) {
            return Response::error('Valid email address required');
        }
        
        $result = $this->driveService->addFolderCollaborator(
            $activeEmail,
            $folderId,
            $collaboratorEmail,
            $permission
        );
        
        if (!$result['success']) {
            return Response::error($result['error'] ?? 'Failed to add collaborator');
        }
        
        // Send email notification
        $folder = $this->driveService->getFolder($activeEmail, $folderId);
        $emailResult = $this->sendCollaboratorInviteEmail($collaboratorEmail, $folder, $permission);
        $result['email_sent'] = $emailResult['success'];
        $result['email_debug'] = $emailResult['debug'];
        if (!$emailResult['success'] && isset($emailResult['error'])) {
            $result['email_error'] = $emailResult['error'];
        }

        // In-app + realtime push (web + mobile) so the recipient is notified
        // inside FlowOne, not only by email. Best-effort; never block the share.
        try {
            (new \Webmail\Services\ShareNotificationService($this->config))->notify(
                $activeEmail,
                $folder['name'] ?? 'a folder',
                null,
                [$collaboratorEmail],
                'folder'
            );
        } catch (\Throwable $e) {
            error_log('DriveController::addCollaborator notify failed: ' . $e->getMessage());
        }

        return Response::success($result, 'Collaborator added');
    }
    
    /**
     * Send email notification to collaborator
     */
    private function sendCollaboratorInviteEmail(string $recipientEmail, ?array $folder, string $permission): array
    {
        $debug = [
            'recipient' => $recipientEmail,
            'folder' => $folder ? $folder['name'] : null,
            'userEmail' => $this->userEmail,
            'hasPassword' => !empty($this->userPassword),
            'passwordLength' => $this->userPassword ? strlen($this->userPassword) : 0,
            'isOAuthSession' => $this->isOAuthSession,
            'oauthProvider' => $this->oauthProvider,
            'step' => 'init',
        ];
        
        // Log to file
        $logFile = '/var/www/vps-email/backend/storage/email_debug.log';
        $logEntry = date('Y-m-d H:i:s') . " - sendCollaboratorInviteEmail\n" . json_encode($debug, JSON_PRETTY_PRINT) . "\n\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
        
        if (!$folder || !$this->userEmail) {
            $debug['step'] = 'failed_no_folder_or_email';
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " - FAILED: No folder or userEmail\n\n", FILE_APPEND);
            return ['success' => false, 'error' => 'Missing folder or user email', 'debug' => $debug];
        }
        
        try {
            $smtp = null;
            
            // Check if using OAuth or password authentication
            if ($this->isOAuthSession && $this->oauthProvider) {
                $debug['step'] = 'oauth_flow';
                $accessToken = null;
                $smtpConfig = null;
                
                if ($this->oauthProvider === 'microsoft' && $this->microsoftOAuthService) {
                    $accessToken = $this->microsoftOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                    $smtpConfig = [
                        'host' => 'smtp.office365.com',
                        'port' => 587,
                        'encryption' => 'tls',
                        'auth' => true,
                    ];
                } elseif ($this->googleOAuthService) {
                    $accessToken = $this->googleOAuthService->getValidAccessToken($this->userEmail, $this->userEmail);
                    $smtpConfig = [
                        'host' => 'smtp.gmail.com',
                        'port' => 587,
                        'encryption' => 'tls',
                        'auth' => true,
                    ];
                }
                
                if (!$accessToken) {
                    $debug['step'] = 'failed_no_oauth_token';
                    @file_put_contents($logFile, date('Y-m-d H:i:s') . " - FAILED: No OAuth token\n\n", FILE_APPEND);
                    return ['success' => false, 'error' => 'Failed to get OAuth access token', 'debug' => $debug];
                }
                
                $smtp = new SmtpService($smtpConfig);
                $smtp->setOAuthCredentials($this->userEmail, $accessToken, $this->oauthProvider);
                $debug['step'] = 'oauth_configured';
                
            } elseif ($this->userPassword) {
                $debug['step'] = 'password_flow';
                $debug['smtpHost'] = $this->config['smtp']['host'] ?? 'not_set';
                $debug['smtpPort'] = $this->config['smtp']['port'] ?? 'not_set';
                
                $smtp = new SmtpService($this->config['smtp']);
                $smtp->setCredentials($this->userEmail, $this->userPassword);
                $debug['step'] = 'password_configured';
                
            } else {
                $debug['step'] = 'failed_no_credentials';
                @file_put_contents($logFile, date('Y-m-d H:i:s') . " - FAILED: No credentials\n\n", FILE_APPEND);
                return ['success' => false, 'error' => 'No credentials available (not OAuth, no password)', 'debug' => $debug];
            }
            
            $folderName = $folder['name'];
            $permissionText = $permission === 'editor' ? 'edit' : 'view';
            $baseUrl = $this->config['app']['frontend_url'] ?? 'https://flowone.pro';
            
            $htmlBody = $this->buildCollaboratorInviteEmailHtml($folderName, $this->userEmail, $permissionText, $baseUrl);
            
            $debug['step'] = 'sending_email';
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " - Attempting to send email...\n", FILE_APPEND);
            
            $sendResult = $smtp->send([
                'from_name' => 'Drive Sharing',
                'to' => [['email' => $recipientEmail, 'name' => '']],
                'subject' => "{$this->userEmail} shared a folder with you: {$folderName}",
                'body_html' => $htmlBody,
                'body_text' => "{$this->userEmail} has shared a folder with you.\n\nFolder: {$folderName}\nPermission: {$permissionText}\n\nYou can access it at {$baseUrl}",
            ]);
            
            $debug['step'] = 'email_sent';
            $debug['smtpResult'] = $sendResult;
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " - SMTP Result: " . json_encode($sendResult) . "\n\n", FILE_APPEND);
            
            if ($sendResult['success'] ?? false) {
                return ['success' => true, 'debug' => $debug];
            } else {
                return ['success' => false, 'error' => $sendResult['error'] ?? 'SMTP send failed', 'debug' => $debug];
            }
            
        } catch (\Exception $e) {
            $debug['step'] = 'exception';
            $debug['exception'] = $e->getMessage();
            @file_put_contents($logFile, date('Y-m-d H:i:s') . " - EXCEPTION: " . $e->getMessage() . "\n\n", FILE_APPEND);
            return ['success' => false, 'error' => 'Exception: ' . $e->getMessage(), 'debug' => $debug];
        }
    }
    
    /**
     * Build HTML email for collaborator invitation
     */
    private function buildCollaboratorInviteEmailHtml(string $folderName, string $inviterEmail, string $permissionText, string $baseUrl): string
    {
        $permissionDesc = $permissionText === 'edit' 
            ? 'As an editor, you can view, download, upload, and delete files.'
            : 'As a viewer, you can view and download files.';
        
        $roleLabel = $permissionText === 'edit' ? 'Editor' : 'Viewer';
            
        return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; background-color: #f3f4f6;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table width="100%" cellpadding="0" cellspacing="0" style="max-width: 520px; border-radius: 20px; overflow: hidden; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color: #1f2937; padding: 40px 32px; text-align: center;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td align="center">
                                        <h1 style="color: white; margin: 0 0 8px 0; font-size: 28px; font-weight: 700; letter-spacing: -0.5px;">' . htmlspecialchars($folderName) . '</h1>
                                        <p style="color: rgba(255,255,255,0.7); margin: 0; font-size: 14px;">Folder Shared With You</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="background: white; padding: 36px 32px;">
                            <table width="100%" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="padding-bottom: 24px;">
                                        <p style="font-size: 16px; color: #374151; margin: 0; line-height: 1.6;">
                                            <strong style="color: #111827;">' . htmlspecialchars($inviterEmail) . '</strong> has shared a folder with you on Drive.
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding-bottom: 28px;">
                                        <table width="100%" cellpadding="0" cellspacing="0" style="background: #f8fafc; border-radius: 12px; border-left: 4px solid #6366f1;">
                                            <tr>
                                                <td style="padding: 20px 24px;">
                                                    <p style="margin: 0; font-size: 14px; color: #6b7280; line-height: 1.5;">
                                                        Your role: <strong style="color: #111827;">' . $roleLabel . '</strong>
                                                    </p>
                                                    <p style="margin: 8px 0 0 0; font-size: 13px; color: #9ca3af;">
                                                        ' . htmlspecialchars($permissionDesc) . '
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding-bottom: 24px;">
                                        <table cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="background: #1f2937; border-radius: 10px;">
                                                    <a href="' . htmlspecialchars($baseUrl) . '/#/drive?view=shared" style="display: inline-block; color: white; text-decoration: none; padding: 14px 40px; font-weight: 600; font-size: 15px;">
                                                        Open Shared Folders
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background: #f8fafc; padding: 24px 32px; text-align: center; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0 0 12px 0; font-size: 13px; color: #6b7280;">
                                This folder was shared via <a href="' . htmlspecialchars($baseUrl) . '" style="color: #6366f1; text-decoration: none;">Email App</a> Drive
                            </p>
                            <p style="margin: 0; font-size: 12px; color: #9ca3af;">
                                <a href="' . htmlspecialchars($baseUrl) . '" style="color: #9ca3af; text-decoration: none;">' . htmlspecialchars($baseUrl) . '</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Remove collaborator from a folder
     * DELETE /api/drive/folders/{id}/collaborators/{email}
     */
    public function removeCollaborator(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $folderId = (int)$request->getParam('id');
        $collaboratorEmail = $request->getParam('email');
        
        if (!$this->driveService->removeFolderCollaborator($activeEmail, $folderId, $collaboratorEmail)) {
            return Response::error('Collaborator not found', 404);
        }
        
        return Response::success(null, 'Collaborator removed');
    }
    
    /**
     * Update collaborator permission
     * PUT /api/drive/folders/{id}/collaborators/{email}
     */
    public function updateCollaborator(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $folderId = (int)$request->getParam('id');
        $collaboratorEmail = $request->getParam('email');
        $permission = $request->input('permission');
        
        if (!in_array($permission, ['viewer', 'editor'])) {
            return Response::error('Permission must be "viewer" or "editor"');
        }
        
        if (!$this->driveService->updateCollaboratorPermission($activeEmail, $folderId, $collaboratorEmail, $permission)) {
            return Response::error('Collaborator not found', 404);
        }
        
        return Response::success(null, 'Permission updated');
    }
    
    /**
     * Get folder collaborators
     * GET /api/drive/folders/{id}/collaborators
     */
    public function getCollaborators(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $folderId = (int)$request->getParam('id');
        
        $collaborators = $this->driveService->getFolderCollaborators($activeEmail, $folderId);
        
        return Response::success(['collaborators' => $collaborators]);
    }
    
    /**
     * Get group access for a folder
     * GET /api/drive/folders/{id}/group-access
     */
    public function getGroupAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $folderId = (int)$request->getParam('id');
        
        $groupAccess = $this->driveService->getFolderGroupAccess($activeEmail, $folderId);
        
        return Response::success($groupAccess);
    }
    
    /**
     * Remove group access from a folder
     * DELETE /api/drive/folders/{id}/group-access/{groupId}
     */
    public function removeGroupAccess(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $folderId = (int)$request->getParam('id');
        $groupId = (int)$request->getParam('groupId');
        
        $result = $this->driveService->removeGroupAccess($activeEmail, $folderId, $groupId);
        
        if (!$result['success']) {
            return Response::error($result['error'], 400);
        }
        
        return Response::success(['message' => 'Group access removed']);
    }
    
    /**
     * Get folders and individual files shared with current user
     * GET /api/drive/shared-with-me
     */
    public function getSharedWithMe(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $folders = $this->driveService->getSharedWithMe($activeEmail);
        $fileSharing = new \Webmail\Services\DriveFileSharingService($this->driveService->getDb());
        $files = $fileSharing->getFilesSharedWith($activeEmail);
        
        return Response::success(['folders' => $folders, 'files' => $files]);
    }
    
    /**
     * Get contents of a shared folder (as collaborator)
     * GET /api/drive/shared/{folderId}
     */
    public function getSharedFolderContents(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $folderId = (int)$request->getParam('folderId');
        
        $contents = $this->driveService->getCollaboratorFolderContents($activeEmail, $folderId);
        
        if (!$contents) {
            return Response::error('Folder not found or access denied', 404);
        }
        
        return Response::success($contents);
    }
    
    /**
     * Get contents of a subfolder within a shared folder (as collaborator)
     * GET /api/drive/shared/{folderId}/subfolder/{subfolderId}
     */
    public function getSharedSubfolderContents(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $rootFolderId = (int)$request->getParam('folderId');
        $subfolderId = (int)$request->getParam('subfolderId');
        
        $contents = $this->driveService->getCollaboratorSubfolderContents($activeEmail, $rootFolderId, $subfolderId);
        
        if (!$contents) {
            return Response::error('Folder not found or access denied', 404);
        }
        
        return Response::success($contents);
    }
    
    /**
     * Download file from a shared folder (as collaborator)
     * GET /api/drive/shared/{folderId}/file/{fileId}/download
     */
    public function downloadSharedFile(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $folderId = (int)$request->getParam('folderId');
        $fileId = (int)$request->getParam('fileId');
        
        $file = $this->driveService->getSharedFileForDownload($activeEmail, $folderId, $fileId);
        
        if (!$file) {
            return Response::error('File not found or access denied', 404);
        }

        // View-only restriction: block downloads for VIEW-access recipients.
        if ($this->driveService->isViewerDownloadBlocked($file, $activeEmail)) {
            $this->driveService->logFileAccess(
                (int)$file['id'], $activeEmail, 'download_blocked',
                $request->getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            return Response::error('Downloading is disabled for this file', 403);
        }
        
        $filePath = $file['storage_path'];
        if (!file_exists($filePath)) {
            return Response::error('File not found on disk', 404);
        }
        
        header('Content-Type: ' . ($file['mime_type'] ?? 'application/octet-stream'));
        header($this->safeContentDisposition('attachment', $file['original_name']));
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: no-cache, must-revalidate');
        
        $this->sendFileViaOls($filePath);
    }
    
    /**
     * Preview file from a shared folder (as collaborator)
     * GET /api/drive/shared/{folderId}/file/{fileId}/preview
     */
    public function previewSharedFile(Request $request): Response
    {
        try {
            $authError = $this->requireAuth($request);
            if ($authError) return $authError;
            
            $activeEmail = $this->getActiveEmail();
            $folderId = (int)$request->getParam('folderId');
            $fileId = (int)$request->getParam('fileId');
            
            error_log("previewSharedFile: user=$activeEmail, folderId=$folderId, fileId=$fileId");
            
            $file = $this->driveService->getSharedFileForDownload($activeEmail, $folderId, $fileId);
            
            if (!$file) {
                error_log("previewSharedFile: File not found or access denied");
                return Response::error('File not found or access denied', 404);
            }
            
            $filePath = $file['storage_path'];
            error_log("previewSharedFile: storage_path=$filePath");
            
            if (!file_exists($filePath)) {
                error_log("previewSharedFile: File not found on disk at $filePath");
                return Response::error('File not found on disk', 404);
            }

            // Record the open for the file's access history.
            $this->driveService->logFileAccess(
                (int)$file['id'], $activeEmail, 'open',
                $request->getClientIp(), $_SERVER['HTTP_USER_AGENT'] ?? null
            );
            
            header('Content-Type: ' . ($file['mime_type'] ?? 'application/octet-stream'));
            header($this->safeContentDisposition('inline', $file['original_name']));
            header('Content-Length: ' . filesize($filePath));
            header('Cache-Control: public, max-age=86400');
            
            $this->sendFileViaOls($filePath);
        } catch (\Exception $e) {
            error_log("previewSharedFile exception: " . $e->getMessage());
            return Response::error('Internal error: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Upload file to a shared folder (as collaborator)
     * POST /api/drive/shared/{folderId}/upload
     */
    public function uploadToSharedFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        if (empty($_FILES['file'])) {
            return Response::error('No file uploaded');
        }
        
        $activeEmail = $this->getActiveEmail();
        $folderId = (int)$request->getParam('folderId');
        
        $file = $this->driveService->uploadFileAsCollaborator($activeEmail, $folderId, $_FILES['file']);
        
        if (!$file) {
            return Response::error('Failed to upload file - access denied or quota exceeded');
        }
        
        return Response::success(['file' => $file], 'File uploaded');
    }

    /**
     * Create subfolder inside a shared folder tree (as collaborator)
     * POST /api/drive/shared/{folderId}/folders
     */
    public function createFolderInSharedFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;

        $activeEmail = $this->getActiveEmail();
        $folderId = (int)$request->getParam('folderId');
        $name = trim((string)$request->input('name', ''));

        if ($name === '') {
            return Response::error('Folder name is required');
        }

        $folder = $this->driveService->createFolderAsCollaborator($activeEmail, $folderId, $name);
        if (!$folder) {
            return Response::error('Failed to create folder - access denied or invalid parent');
        }

        return Response::success(['folder' => $folder], 'Folder created');
    }
    
    /**
     * Delete file from a shared folder (as collaborator)
     * DELETE /api/drive/shared/files/{id}
     */
    public function deleteFromSharedFolder(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $fileId = (int)$request->getParam('id');
        
        if (!$this->driveService->deleteFileAsCollaborator($activeEmail, $fileId)) {
            return Response::error('File not found or access denied', 404);
        }
        
        return Response::success(null, 'File deleted');
    }
    
    /**
     * Debug endpoint to check folder download issues
     */
    public function debugFolderDownload(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $folderId = !empty($_GET['folder']) ? (int)$_GET['folder'] : null;
        
        $debug = $this->driveService->debugFolderContents($activeEmail, $folderId);
        
        return Response::success($debug);
    }
    
    // =====================================================
    // SYNC EVENTS - Real-time notifications
    // =====================================================
    
    /**
     * Get sync events since a timestamp
     * GET /api/drive/sync-events?since=1705123456
     * Returns lightweight list of file changes for efficient polling
     */
    public function getSyncEvents(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) {
            return $authError;
        }
        
        $activeEmail = $this->getActiveEmail();
        
        // Use getQuery for GET request parameters
        $since = $request->getQuery('since') ? (int)$request->getQuery('since') : null;
        $limit = $request->getQuery('limit') ? (int)$request->getQuery('limit') : 50;
        $offset = $request->getQuery('offset') ? (int)$request->getQuery('offset') : 0;
        $all = $request->getQuery('all') === 'true' || $request->getQuery('all') === '1';
        
        // Cap limit at 200 for safety
        $limit = min($limit, 200);
        
        try {
            $db = $this->driveService->getDb();
            
            // Check if table exists first
            $tableCheck = $db->query("SHOW TABLES LIKE 'webmail_drive_sync_events'");
            if ($tableCheck->rowCount() === 0) {
                // Table doesn't exist - create it
                $db->exec("
                    CREATE TABLE IF NOT EXISTS webmail_drive_sync_events (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        user_email VARCHAR(255) NOT NULL,
                        event_type ENUM('file_created', 'file_updated', 'file_deleted', 'folder_created', 'folder_deleted') NOT NULL,
                        file_id INT NULL,
                        folder_id INT NULL,
                        file_name VARCHAR(255) NULL,
                        new_version INT NULL,
                        modified_by VARCHAR(255) NULL,
                        source VARCHAR(50) DEFAULT 'web',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_user_created (user_email, created_at),
                        INDEX idx_created_at (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            
            if ($all) {
                // Fetch all events for activity log (with pagination)
                $sql = "
                    SELECT id, event_type, file_id, folder_id, file_name, new_version, modified_by, source,
                           UNIX_TIMESTAMP(created_at) as timestamp, created_at
                    FROM webmail_drive_sync_events 
                    WHERE user_email = ?
                    ORDER BY created_at DESC
                    LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
                $stmt = $db->prepare($sql);
                $stmt->execute([strtolower($activeEmail)]);
            } else {
                // Get events since timestamp for real-time polling
                $sinceTime = $since ?? (time() - 60);
                $sql = "
                    SELECT id, event_type, file_id, folder_id, file_name, new_version, modified_by, source,
                           UNIX_TIMESTAMP(created_at) as timestamp, created_at
                    FROM webmail_drive_sync_events 
                    WHERE user_email = ? AND UNIX_TIMESTAMP(created_at) > ?
                    ORDER BY created_at DESC
                    LIMIT " . (int)$limit;
                $stmt = $db->prepare($sql);
                $stmt->execute([strtolower($activeEmail), $sinceTime]);
            }
            
            $events = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Get total count for pagination (only if fetching all)
            $total = 0;
            if ($all) {
                $countStmt = $db->prepare("SELECT COUNT(*) FROM webmail_drive_sync_events WHERE user_email = ?");
                $countStmt->execute([strtolower($activeEmail)]);
                $total = (int)$countStmt->fetchColumn();
            }
            
            return Response::success([
                'events' => $events,
                'total' => $total,
                'server_time' => time()
            ]);
        } catch (\Throwable $e) {
            error_log("[getSyncEvents] Error: " . $e->getMessage());
            return Response::success(['events' => [], 'total' => 0, 'server_time' => time()]);
        }
    }
    
    /**
     * Delete a single sync event
     * DELETE /api/drive/sync-events/{id}
     */
    public function deleteSyncEvent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $eventId = (int)$request->getParam('id');
        
        if (!$eventId) {
            return Response::error('Event ID required', 400);
        }
        
        try {
            $db = $this->driveService->getDb();
            
            // Only delete if event belongs to this user
            $stmt = $db->prepare("DELETE FROM webmail_drive_sync_events WHERE id = ? AND user_email = ?");
            $stmt->execute([$eventId, strtolower($activeEmail)]);
            
            if ($stmt->rowCount() === 0) {
                return Response::error('Event not found', 404);
            }
            
            return Response::success(null, 'Event deleted');
        } catch (\Exception $e) {
            error_log("deleteSyncEvent error: " . $e->getMessage());
            return Response::error('Failed to delete event', 500);
        }
    }
    
    /**
     * Clear all sync events for user
     * DELETE /api/drive/sync-events
     */
    public function clearSyncEvents(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        try {
            $db = $this->driveService->getDb();
            
            $stmt = $db->prepare("DELETE FROM webmail_drive_sync_events WHERE user_email = ?");
            $stmt->execute([strtolower($activeEmail)]);
            
            $deletedCount = $stmt->rowCount();
            
            return Response::success(['deleted' => $deletedCount], 'All events cleared');
        } catch (\Exception $e) {
            error_log("clearSyncEvents error: " . $e->getMessage());
            return Response::error('Failed to clear events', 500);
        }
    }
    
    /**
     * Record a sync event (called by Electron app or internal)
     * POST /api/drive/sync-events
     */
    public function recordSyncEvent(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $eventType = $request->input('event_type');
        $fileId = $request->input('file_id');
        $folderId = $request->input('folder_id');
        $fileName = $request->input('file_name');
        $newVersion = $request->input('new_version');
        $source = $request->input('source') ?? 'electron';
        
        if (!$eventType) {
            return Response::error('event_type is required');
        }
        
        $validTypes = ['file_created', 'file_updated', 'file_deleted', 'folder_created', 'folder_deleted'];
        if (!in_array($eventType, $validTypes)) {
            return Response::error('Invalid event_type');
        }
        
        $success = $this->createSyncEvent($activeEmail, $eventType, [
            'file_id' => $fileId,
            'folder_id' => $folderId,
            'file_name' => $fileName,
            'new_version' => $newVersion,
            'source' => $source
        ]);
        
        if ($success) {
            return Response::success(null, 'Event recorded');
        } else {
            return Response::error('Failed to record event');
        }
    }
    
    /**
     * Internal helper to create a sync event
     * Includes duplicate detection - won't create identical event within 5 seconds
     */
    private function createSyncEvent(string $userEmail, string $eventType, array $data): bool
    {
        try {
            $db = $this->driveService->getDb();
            
            // Ensure table exists
            $db->exec("
                CREATE TABLE IF NOT EXISTS webmail_drive_sync_events (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_email VARCHAR(255) NOT NULL,
                    event_type ENUM('file_created', 'file_updated', 'file_deleted', 'folder_created', 'folder_deleted') NOT NULL,
                    file_id INT NULL,
                    folder_id INT NULL,
                    file_name VARCHAR(255) NULL,
                    new_version INT NULL,
                    modified_by VARCHAR(255) NULL,
                    source VARCHAR(50) DEFAULT 'web',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_created (user_email, created_at),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            // Check for duplicate event in the last 5 seconds
            $fileId = $data['file_id'] ?? null;
            $fileName = $data['file_name'] ?? null;
            $newVersion = $data['new_version'] ?? null;
            
            $duplicateCheck = $db->prepare("
                SELECT id FROM webmail_drive_sync_events 
                WHERE user_email = ? 
                  AND event_type = ? 
                  AND (file_id = ? OR (file_id IS NULL AND ? IS NULL))
                  AND (file_name = ? OR (file_name IS NULL AND ? IS NULL))
                  AND (new_version = ? OR (new_version IS NULL AND ? IS NULL))
                  AND created_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
                LIMIT 1
            ");
            $duplicateCheck->execute([
                strtolower($userEmail),
                $eventType,
                $fileId, $fileId,
                $fileName, $fileName,
                $newVersion, $newVersion
            ]);
            
            if ($duplicateCheck->fetch()) {
                // Duplicate found, skip creating
                return true;
            }
            
            $stmt = $db->prepare("
                INSERT INTO webmail_drive_sync_events 
                (user_email, event_type, file_id, folder_id, file_name, new_version, modified_by, source)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $inserted = $stmt->execute([
                strtolower($userEmail),
                $eventType,
                $fileId,
                $data['folder_id'] ?? null,
                $fileName,
                $newVersion,
                $data['modified_by'] ?? $userEmail,
                $data['source'] ?? 'web'
            ]);
            
            // Real-time push: broadcast over the existing Redis -> Node mailsync
            // -> WebSocket pipeline so the same user's OTHER open devices/tabs
            // refresh instantly instead of waiting for the slow HTTP poll.
            if ($inserted) {
                $this->publishDriveRealtimeEvent($userEmail, $eventType, [
                    'file_id' => $fileId,
                    'folder_id' => $data['folder_id'] ?? null,
                    'file_name' => $fileName,
                    'new_version' => $newVersion,
                    'modified_by' => $data['modified_by'] ?? $userEmail,
                    'source' => $data['source'] ?? 'web',
                ]);
            }
            
            return $inserted;
        } catch (\Exception $e) {
            error_log("createSyncEvent error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Publish a Drive change to the user's real-time channel.
     *
     * Maps the internal sync-event type to the DRIVE_* WebSocket event type
     * that the Node mailsync server already understands and relays to every
     * connected client of this user. A Redis outage must never break the
     * underlying file operation, so failures are swallowed.
     */
    private function publishDriveRealtimeEvent(string $userEmail, string $eventType, array $payload): void
    {
        if (!$this->redisCache) {
            return;
        }
        
        $map = [
            'file_created'   => 'DRIVE_FILE_CREATED',
            'file_updated'   => 'DRIVE_FILE_UPDATED',
            'file_deleted'   => 'DRIVE_FILE_DELETED',
            'folder_created' => 'DRIVE_FOLDER_CREATED',
            'folder_deleted' => 'DRIVE_FOLDER_DELETED',
        ];
        
        $wsType = $map[$eventType] ?? null;
        if ($wsType === null) {
            return;
        }
        
        try {
            $this->redisCache->publishEvent(strtolower($userEmail), $wsType, $payload);
        } catch (\Throwable $e) {
            error_log("[DriveController] publishDriveRealtimeEvent error: " . $e->getMessage());
        }
    }
    
    // ========================
    // FILE EDITING STATUS
    // ========================
    
    /**
     * Set editing status for a file (user opened/closed a file)
     * POST /api/drive/editing-status
     * 
     * Body: { filename: string, folder_id?: number, is_editing: boolean }
     */
    public function setEditingStatus(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $filename = $request->input('filename');
        $folderId = $request->input('folder_id');
        $isEditing = $request->input('is_editing', true);
        
        if (!$filename) {
            return Response::error('filename is required', 400);
        }
        
        try {
            $result = $this->driveService->setEditingStatus(
                $activeEmail,
                $filename,
                $folderId ? (int)$folderId : null,
                (bool)$isEditing
            );
            
            if ($result) {
                return Response::success([
                    'editing' => $isEditing,
                    'filename' => $filename,
                    'user' => $activeEmail
                ], $isEditing ? 'Editing status set' : 'Editing status cleared');
            } else {
                return Response::error('Failed to update editing status', 500);
            }
        } catch (\Exception $e) {
            error_log("setEditingStatus error: " . $e->getMessage());
            return Response::error('Failed to update editing status', 500);
        }
    }
    
    /**
     * Clear editing status for a file
     * DELETE /api/drive/editing-status
     * 
     * Body: { filename: string, folder_id?: number }
     */
    public function clearEditingStatus(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $filename = $request->input('filename');
        $folderId = $request->input('folder_id');
        
        $logFile = __DIR__ . '/../../logs/php_errors.log';
        error_log("[clearEditingStatus] User: $activeEmail, filename: " . ($filename ?? 'null') . ", folder_id: " . ($folderId ?? 'null') . "\n", 3, $logFile);
        
        if (!$filename) {
            error_log("[clearEditingStatus] ERROR: filename is required. Raw body: " . json_encode($request->input()) . "\n", 3, $logFile);
            return Response::error('filename is required', 400);
        }
        
        try {
            $this->driveService->setEditingStatus(
                $activeEmail,
                $filename,
                $folderId ? (int)$folderId : null,
                false // Clear editing status
            );
            
            error_log("[clearEditingStatus] SUCCESS: Cleared editing status for $filename\n", 3, $logFile);
            return Response::success(null, 'Editing status cleared');
        } catch (\Exception $e) {
            error_log("[clearEditingStatus] EXCEPTION: " . $e->getMessage() . "\n", 3, $logFile);
            return Response::error('Failed to clear editing status', 500);
        }
    }
    
    /**
     * Get who is editing files in a folder
     * GET /api/drive/editing-status
     * 
     * Query: folder_id (optional, null for root)
     */
    public function getEditingStatus(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $folderId = $request->getQuery('folder_id');
        
        try {
            $editors = $this->driveService->getEditingStatus(
                $activeEmail,
                $folderId ? (int)$folderId : null
            );
            
            return Response::success([
                'editors' => $editors,
                'folder_id' => $folderId
            ]);
        } catch (\Exception $e) {
            error_log("getEditingStatus error: " . $e->getMessage());
            return Response::error('Failed to get editing status', 500);
        }
    }
    
    /**
     * Get all active editors in shared folders
     * GET /api/drive/editing-status/shared
     */
    public function getSharedEditingStatus(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        try {
            $editors = $this->driveService->getSharedFolderEditors($activeEmail);
            
            return Response::success([
                'editors' => $editors
            ]);
        } catch (\Exception $e) {
            error_log("getSharedEditingStatus error: " . $e->getMessage());
            return Response::error('Failed to get shared editing status', 500);
        }
    }
    
    /**
     * Heartbeat to keep editing session alive
     * POST /api/drive/editing-status/heartbeat
     * 
     * Body: { filename: string, folder_id?: number }
     */
    public function heartbeatEditingStatus(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $filename = $request->input('filename');
        $folderId = $request->input('folder_id');
        
        if (!$filename) {
            return Response::error('filename is required', 400);
        }
        
        try {
            $result = $this->driveService->heartbeatEditingStatus(
                $activeEmail,
                $filename,
                $folderId ? (int)$folderId : null
            );
            
            return Response::success(['alive' => $result]);
        } catch (\Exception $e) {
            error_log("heartbeatEditingStatus error: " . $e->getMessage());
            return Response::error('Failed to update heartbeat', 500);
        }
    }
    
    // ============================================
    // NAS DIRECT ACCESS ENDPOINTS
    // ============================================
    
    /**
     * Get NAS connection configuration for desktop clients
     * GET /api/drive/connection-config
     * 
     * Returns NAS IP, paths, and settings so desktop apps can
     * access NAS directly when on the same network (faster than server relay)
     */
    public function getConnectionConfig(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        try {
            // Get NAS config from database or defaults
            $nasConfig = $this->getNasConfig();
            
            // Check if storage service reports Panel-based storage
            $storageInfo = $this->driveService->getStorageInfo();
            
            return Response::success([
                'nas' => [
                    'enabled' => $nasConfig['enabled'] ?? true,
                    'ip' => $nasConfig['ip'] ?? '192.168.1.106',
                    'smb_share' => $nasConfig['smb_share'] ?? 'mailflow-drive',
                    'nfs_path' => $nasConfig['nfs_path'] ?? '/volume1/mailflow-drive',
                    'user_folder' => $activeEmail, // User's subfolder on NAS
                    'direct_access_enabled' => $nasConfig['direct_access_enabled'] ?? true,
                ],
                'server' => [
                    'api_url' => rtrim($this->config['app']['url'] ?? 'https://flowone.pro', '/') . '/api',
                    'storage_type' => $storageInfo['driver'] ?? 'local',
                    'storage_source' => $storageInfo['source'] ?? 'fallback',
                ],
                'sync' => [
                    'interval_seconds' => 30,
                    'conflict_strategy' => 'server-wins',
                ],
            ]);
        } catch (\Exception $e) {
            error_log("getConnectionConfig error: " . $e->getMessage());
            return Response::error('Failed to get connection config', 500);
        }
    }
    
    /**
     * Register a file uploaded directly to NAS (metadata only)
     * POST /api/drive/files/register
     * 
     * When desktop client uploads directly to NAS, it calls this
     * to register the file in the database (without uploading bytes)
     * 
     * Body: {
     *   filename: string,
     *   folder_id?: number,
     *   size: number,
     *   checksum: string (MD5),
     *   mime_type?: string,
     *   nas_relative_path: string
     * }
     */
    public function registerFile(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        
        $filename = $request->input('filename');
        $folderId = $request->input('folder_id');
        $size = $request->input('size');
        $checksum = $request->input('checksum');
        $mimeType = $request->input('mime_type');
        $nasRelativePath = $request->input('nas_relative_path');
        
        if (!$filename || $size === null || !$checksum || !$nasRelativePath) {
            return Response::error('filename, size, checksum, and nas_relative_path are required', 400);
        }
        
        try {
            // Check if file already exists (by checksum in same folder)
            $existingFile = $this->driveService->findFileByChecksum($activeEmail, $checksum, $folderId);
            
            if ($existingFile) {
                // File already exists, just update the nas_relative_path
                $this->driveService->updateFileNasPath($existingFile['id'], $nasRelativePath);
                
                return Response::success([
                    'file' => array_merge($existingFile, ['nas_relative_path' => $nasRelativePath]),
                    'action' => 'updated',
                ], 'File metadata updated');
            }
            
            // Determine mime type if not provided
            if (!$mimeType) {
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $mimeType = $this->getMimeTypeForExtension($ext);
            }
            
            // Register the new file
            $file = $this->driveService->registerNasFile(
                $activeEmail,
                $filename,
                $folderId ? (int)$folderId : null,
                (int)$size,
                $checksum,
                $mimeType,
                $nasRelativePath
            );
            
            if ($file) {
                // Index for search
                $this->triggerFileIndex($file, $folderId ? (int)$folderId : null);
                
                // Create sync event
                $this->driveService->createSyncEvent(
                    $activeEmail,
                    'upload',
                    'file',
                    $file['id'],
                    $filename,
                    'success',
                    'Direct NAS upload'
                );
                
                return Response::success([
                    'file' => $file,
                    'action' => 'created',
                ], 'File registered');
            }
            
            return Response::error('Failed to register file', 500);
        } catch (\Exception $e) {
            error_log("registerFile error: " . $e->getMessage());
            return Response::error('Failed to register file: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Update file metadata after direct NAS modification
     * PUT /api/drive/files/{id}/metadata
     * 
     * Body: {
     *   checksum?: string,
     *   size?: number
     * }
     */
    public function updateFileMetadata(Request $request): Response
    {
        $authError = $this->requireAuth($request);
        if ($authError) return $authError;
        
        $activeEmail = $this->getActiveEmail();
        $fileId = $request->getParam('id');
        
        if (!$fileId) {
            return Response::error('File ID is required', 400);
        }
        
        $checksum = $request->input('checksum');
        $size = $request->input('size');
        
        if (!$checksum && $size === null) {
            return Response::error('At least one of checksum or size is required', 400);
        }
        
        try {
            // Verify user owns this file
            $file = $this->driveService->getFile($activeEmail, (int)$fileId);
            
            if (!$file) {
                return Response::error('File not found', 404);
            }
            
            // NOTE: this endpoint no longer creates version rows. The old
            // behavior inserted a "version" pointing at a file the client
            // had ALREADY overwritten in place, so the row referenced the
            // new bytes and the old content was unrecoverable. Clients
            // that want real version history must write new content to a
            // fresh filename and call POST /drive/files/{id}/content-commit
            // (see DriveVersionsController::contentCommit).
            $versionCreated = false;
            
            // Update file metadata
            $updates = [];
            if ($checksum) {
                $updates['checksum'] = $checksum;
            }
            if ($size !== null) {
                $updates['size'] = (int)$size;
                // Direct NAS writes change bytes on disk without going
                // through an upload path - keep the quota ledger in step.
                $sizeDelta = (int)$size - (int)$file['size'];
                if ($sizeDelta !== 0) {
                    $this->driveService->updateUsedSpace($activeEmail, $sizeDelta);
                }
            }
            $updates['updated_at'] = date('Y-m-d H:i:s');
            $updates['last_modified_by'] = $activeEmail;
            
            $updatedFile = $this->driveService->updateFileMeta((int)$fileId, $updates);
            
            // Create sync event
            $this->driveService->createSyncEvent(
                $activeEmail,
                'update',
                'file',
                (int)$fileId,
                $file['original_name'],
                'success',
                'Direct NAS modification'
            );
            
            return Response::success([
                'file' => $updatedFile,
                'version_created' => $versionCreated,
            ], 'File metadata updated');
        } catch (\Exception $e) {
            error_log("updateFileMetadata error: " . $e->getMessage());
            return Response::error('Failed to update file metadata', 500);
        }
    }
    
    /**
     * Get NAS configuration from database
     */
    private function getNasConfig(): array
    {
        try {
            $db = \Webmail\Core\Database::getConnection($this->config);
            
            // Check if table exists
            $tableCheck = $db->query("SHOW TABLES LIKE 'nas_connection_config'")->fetch();
            if (!$tableCheck) {
                // Table doesn't exist yet, return defaults
                return [
                    'enabled' => true,
                    'ip' => '192.168.1.106',
                    'smb_share' => 'mailflow-drive',
                    'nfs_path' => '/volume1/mailflow-drive',
                    'direct_access_enabled' => true,
                ];
            }
            
            $stmt = $db->query('SELECT config_key, config_value FROM nas_connection_config');
            $rows = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
            
            return [
                'enabled' => ($rows['nas_enabled'] ?? 'true') === 'true',
                'ip' => $rows['nas_ip'] ?? '192.168.1.106',
                'smb_share' => $rows['nas_smb_share'] ?? 'mailflow-drive',
                'nfs_path' => $rows['nas_nfs_path'] ?? '/volume1/mailflow-drive',
                'direct_access_enabled' => ($rows['direct_access_enabled'] ?? 'true') === 'true',
            ];
        } catch (\Exception $e) {
            error_log("getNasConfig error: " . $e->getMessage());
            // Return defaults on error
            return [
                'enabled' => true,
                'ip' => '192.168.1.106',
                'smb_share' => 'mailflow-drive',
                'nfs_path' => '/volume1/mailflow-drive',
                'direct_access_enabled' => true,
            ];
        }
    }
    
    /**
     * Get MIME type for file extension
     */
    private function getMimeTypeForExtension(string $ext): string
    {
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'html' => 'text/html',
            'css' => 'text/css',
            'js' => 'application/javascript',
        ];
        
        return $mimeTypes[$ext] ?? 'application/octet-stream';
    }
}

