<?php

namespace Webmail\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Webmail\Core\Database;

/**
 * OfficeEditorService - OnlyOffice Document Server integration.
 *
 * Builds signed editor configs for Drive office files (docx/xlsx/pptx),
 * manages per-file document keys (the co-editing session identity), and
 * handles the Document Server save callback that writes edited files back
 * into Drive.
 *
 * Settings resolution order:
 *   1. $config['office'] in config.php
 *   2. backend/storage/office-config.json (written by install-onlyoffice.sh)
 */
class OfficeEditorService
{
    // Formats that open in the OnlyOffice editor (md is edited as a word doc;
    // Document Server 8.1+ converts md in/out natively).
    public const EDITABLE_EXTENSIONS = ['docx', 'xlsx', 'pptx', 'md'];

    // Formats we can create as blank files (no blank-md generator needed).
    public const CREATABLE_EXTENSIONS = ['docx', 'xlsx', 'pptx'];

    // FlowOne presence plugin (live cursors + follow mode). Installed into
    // the Document Server's sdkjs-plugins/ folder (see email/office-plugins/).
    public const PRESENCE_PLUGIN_GUID = 'asc.{8A4E7C2D-91B3-4D5F-AE60-FF3D4C7B5A19}';

    private const DOCUMENT_TYPES = [
        'docx' => 'word',
        'xlsx' => 'cell',
        'pptx' => 'slide',
        'csv'  => 'cell',
        'md'   => 'word',
    ];

    private array $config;
    private \PDO $db;
    private ?array $settings = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = Database::getConnection($config);
    }

    // =========================================================================
    // Settings
    // =========================================================================

    public function getSettings(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        // The installer writes backend/storage/office-config.json; ops may also
        // place it in the global storage_path. Check both so a deploy can never
        // silently disable the editor because of a path mismatch.
        $fromFile = [];
        $candidates = [
            $this->storagePath() . '/office-config.json',
            dirname(__DIR__, 2) . '/storage/office-config.json',
        ];
        foreach (array_unique($candidates) as $jsonPath) {
            if (!is_file($jsonPath) || !is_readable($jsonPath)) {
                continue;
            }
            $decoded = json_decode((string)@file_get_contents($jsonPath), true);
            if (is_array($decoded)) {
                $fromFile = $decoded;
                break;
            }
        }

        $fromConfig = $this->config['office'] ?? [];
        $merged = array_merge($fromFile, array_filter($fromConfig, fn($v) => $v !== null && $v !== ''));

        $this->settings = [
            'enabled'      => (bool)($merged['enabled'] ?? false),
            'server_url'   => rtrim((string)($merged['server_url'] ?? ''), '/'),
            'internal_url' => rtrim((string)($merged['internal_url'] ?? 'http://127.0.0.1:8090'), '/'),
            'jwt_secret'   => (string)($merged['jwt_secret'] ?? ''),
        ];

        return $this->settings;
    }

    public function isEnabled(): bool
    {
        $s = $this->getSettings();
        return $s['enabled'] && $s['server_url'] !== '' && $s['jwt_secret'] !== '';
    }

    private function storagePath(): string
    {
        $path = $this->config['storage_path'] ?? null;
        if (is_string($path) && $path !== '') {
            return rtrim($path, '/');
        }
        return dirname(__DIR__, 2) . '/storage';
    }

    private function apiBaseUrl(): string
    {
        return rtrim($this->config['app']['api_url'] ?? 'https://flowone.pro/api', '/');
    }

    // =========================================================================
    // File type helpers
    // =========================================================================

    public static function fileExtension(array $file): string
    {
        return strtolower(pathinfo($file['original_name'] ?? '', PATHINFO_EXTENSION));
    }

    public static function isEditableFile(array $file): bool
    {
        return in_array(self::fileExtension($file), self::EDITABLE_EXTENSIONS, true);
    }

    /**
     * Build the canonical new name for a rename: strip whatever extension the
     * client sent and re-apply the file's real one, and drop path separators /
     * null bytes. A rename can therefore never change (or break) the document
     * type or escape its directory.
     *
     * Returns '' when the resulting base name is empty (caller should reject).
     */
    public static function normalizeRenameTarget(string $rawName, array $file): string
    {
        $base = trim(preg_replace('/\.[^.\/\\\\]+$/', '', trim($rawName)));
        $base = str_replace(['/', '\\', "\0"], '', $base);
        if ($base === '') {
            return '';
        }
        $ext = self::fileExtension($file);
        return $ext !== '' ? ($base . '.' . $ext) : $base;
    }

    // =========================================================================
    // Document keys (co-editing session identity)
    // =========================================================================

    /**
     * Everyone opening the same file must get the same key. Rotates when the
     * file was modified outside the editor (re-upload, version restore) so
     * the Document Server doesn't serve a stale cached copy.
     */
    public function getOrCreateDocKey(array $file): string
    {
        $fileId = (int)$file['id'];
        $version = (int)($file['current_version'] ?? 1);
        $updatedAt = $file['updated_at'] ?? null;

        $stmt = $this->db->prepare('SELECT * FROM office_editor_keys WHERE file_id = ?');
        $stmt->execute([$fileId]);
        $row = $stmt->fetch();

        if ($row) {
            $stale = ((int)$row['file_version'] !== $version)
                || ($updatedAt !== null && $row['file_updated_at'] !== null && $row['file_updated_at'] !== $updatedAt);
            if (!$stale) {
                return $row['doc_key'];
            }
            $newKey = $this->generateDocKey($fileId);
            $upd = $this->db->prepare('
                UPDATE office_editor_keys
                SET doc_key = ?, file_version = ?, file_updated_at = ?
                WHERE file_id = ?
            ');
            $upd->execute([$newKey, $version, $updatedAt, $fileId]);
            return $newKey;
        }

        $newKey = $this->generateDocKey($fileId);
        $ins = $this->db->prepare('
            INSERT INTO office_editor_keys (file_id, doc_key, file_version, file_updated_at)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE doc_key = VALUES(doc_key), file_version = VALUES(file_version), file_updated_at = VALUES(file_updated_at)
        ');
        $ins->execute([$fileId, $newKey, $version, $updatedAt]);
        return $newKey;
    }

    /**
     * Rotate after a final save so the next open starts a fresh DS session
     * pointing at the new file content.
     */
    public function rotateDocKey(int $fileId, array $freshFile): void
    {
        $upd = $this->db->prepare('
            UPDATE office_editor_keys
            SET doc_key = ?, file_version = ?, file_updated_at = ?
            WHERE file_id = ?
        ');
        $upd->execute([
            $this->generateDocKey($fileId),
            (int)($freshFile['current_version'] ?? 1),
            $freshFile['updated_at'] ?? null,
            $fileId,
        ]);
    }

    private function generateDocKey(int $fileId): string
    {
        return 'f' . $fileId . '-' . bin2hex(random_bytes(8));
    }

    /**
     * Validate that a callback key belongs to the given file (current key OR
     * a recently rotated one - DS may still flush a session after rotation,
     * so we only require the key prefix to match the file id).
     */
    public function keyMatchesFile(string $key, int $fileId): bool
    {
        return str_starts_with($key, 'f' . $fileId . '-');
    }

    // =========================================================================
    // Signed file tokens (DS <-> backend server traffic, no user JWT)
    // =========================================================================

    public function mintFileToken(int $fileId, string $scope, int $ttlSeconds): string
    {
        $payload = rtrim(strtr(base64_encode((string)json_encode([
            'fid' => $fileId,
            'scp' => $scope,
            'exp' => time() + max(60, $ttlSeconds),
        ])), '+/', '-_'), '=');
        $sig = $this->hmac($payload);
        return $payload . '.' . $sig;
    }

    public function verifyFileToken(string $token, int $fileId, string $scope): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return false;
        }
        [$payload, $sig] = $parts;
        if (!hash_equals($this->hmac($payload), $sig)) {
            return false;
        }
        $data = json_decode((string)base64_decode(strtr($payload, '-_', '+/')), true);
        if (!is_array($data)) {
            return false;
        }
        return (int)($data['fid'] ?? 0) === $fileId
            && ($data['scp'] ?? '') === $scope
            && (int)($data['exp'] ?? 0) >= time();
    }

    private function hmac(string $payload): string
    {
        $secret = $this->getSettings()['jwt_secret'] ?: ($this->config['jwt']['secret'] ?? 'office');
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $secret, true)), '+/', '-_'), '=');
    }

    // =========================================================================
    // Command service (live document control)
    // =========================================================================

    /**
     * Push a new title to the running editor session(s) so an external rename
     * is reflected live in everyone's editor (the title is otherwise fixed at
     * config time). Uses the Document Server "meta" command.
     *
     * No-op (returns false) when there is no known session key for the file.
     *
     * @param array $file Drive file row with the already-updated original_name
     */
    public function updateDocumentTitle(array $file): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $fileId = (int)$file['id'];
        $title = trim((string)($file['original_name'] ?? ''));
        if ($title === '') {
            return false;
        }

        $stmt = $this->db->prepare('SELECT doc_key FROM office_editor_keys WHERE file_id = ?');
        $stmt->execute([$fileId]);
        $row = $stmt->fetch();
        if (!$row || empty($row['doc_key'])) {
            return false;
        }

        $settings = $this->getSettings();
        $payload = [
            'c' => 'meta',
            'key' => (string)$row['doc_key'],
            'meta' => ['title' => $title],
        ];
        // The command service is JWT-protected with the same shared secret.
        $token = JWT::encode($payload, $settings['jwt_secret'], 'HS256');
        $payload['token'] = $token;

        $url = ($settings['internal_url'] ?: $settings['server_url']) . '/coauthoring/CommandService.ashx';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false || $httpCode >= 400) {
            error_log("[Office] meta(title) command failed (http={$httpCode}, err={$err})");
            return false;
        }

        $decoded = json_decode((string)$resp, true);
        // error 0 = success; 1 = key not found (no live session) which is fine.
        $error = is_array($decoded) ? (int)($decoded['error'] ?? 1) : 1;
        if ($error !== 0) {
            error_log("[Office] meta(title) command returned error={$error} resp={$resp}");
            return false;
        }

        return true;
    }

    // =========================================================================
    // Editor config
    // =========================================================================

    /**
     * Build the full signed OnlyOffice editor config for a Drive file.
     *
     * @param array  $file Drive file row
     * @param string $role 'editor'|'viewer'
     * @param array  $user ['id' => ..., 'name' => ...]
     * @param string $lang UI language ('en'|'hu')
     */
    public function buildEditorConfig(array $file, string $role, array $user, string $lang = 'en'): array
    {
        $settings = $this->getSettings();
        $fileId = (int)$file['id'];
        $ext = self::fileExtension($file);
        $canEdit = $role === 'editor';

        // View-only restrictions (no download / no print) apply ONLY to viewers.
        // The owner and editors are mapped to the 'editor' role upstream and are
        // never restricted. Copy is disabled alongside download to reduce
        // exfiltration of the content.
        $isViewer = $role !== 'editor';
        $noDownload = $isViewer && !empty($file['no_download']);
        $noPrint = $isViewer && !empty($file['no_print']);

        $api = $this->apiBaseUrl();
        $contentToken = $this->mintFileToken($fileId, 'content', 3600);
        $callbackToken = $this->mintFileToken($fileId, 'callback', 86400);

        $frontendUrl = rtrim($this->config['app']['frontend_url'] ?? 'https://flowone.pro', '/');
        $gobackQuery = !empty($file['folder_id']) ? ('?folder=' . (int)$file['folder_id']) : '';

        $config = [
            'type' => 'desktop',
            'documentType' => self::DOCUMENT_TYPES[$ext] ?? 'word',
            'document' => [
                'fileType' => $ext,
                'key' => $this->getOrCreateDocKey($file),
                'title' => $file['original_name'],
                'url' => $api . '/office/files/' . $fileId . '/content?token=' . urlencode($contentToken),
                'permissions' => [
                    'edit' => $canEdit,
                    'comment' => $canEdit,
                    'download' => !$noDownload,
                    'print' => !$noPrint,
                    'copy' => !$noDownload,
                    'fillForms' => $canEdit,
                    'review' => $canEdit,
                    // FlowOne has its own chat; hide the editor's built-in one
                    'chat' => false,
                ],
            ],
            'editorConfig' => [
                'mode' => $canEdit ? 'edit' : 'view',
                'lang' => in_array($lang, ['en', 'hu'], true) ? $lang : 'en',
                'callbackUrl' => $api . '/office/files/' . $fileId . '/callback?token=' . urlencode($callbackToken),
                'user' => [
                    'id' => (string)($user['id'] ?? 'anonymous'),
                    'name' => (string)($user['name'] ?? 'Anonymous'),
                ],
                'customization' => [
                    'autosave' => true,
                    'forcesave' => false,
                    'compactHeader' => false,
                    'help' => false,
                    // Legacy flag for older DS builds; permissions.chat is the
                    // current one (set above). Both off = chat never appears.
                    'chat' => false,
                    // Honored only by paid editions; the CE-level hide is done
                    // via CSS in the Docker image. Kept for future-proofing.
                    'about' => false,
                    'feedback' => ['visible' => false],
                    'goback' => [
                        'url' => $frontendUrl . '/drive' . $gobackQuery,
                        'blank' => false,
                    ],
                    'customer' => [
                        'name' => 'FlowOne',
                        'www' => 'flowone.pro',
                    ],
                    // Header logo + click-through (replaces the OnlyOffice logo
                    // and its onlyoffice.com link). SVGs are deployed with the
                    // office/ folder under the web root.
                    'logo' => [
                        'image' => $frontendUrl . '/office/branding/flowone-logo-dark.svg',
                        'imageDark' => $frontendUrl . '/office/branding/flowone-logo-light.svg',
                        'url' => $frontendUrl,
                    ],
                    'uiTheme' => 'theme-classic-light',
                ],
                // FlowOne presence plugin: live cursors + follow mode. The
                // plugin must be installed on the Document Server (same origin
                // as the editor) so it can read the editor DOM; if the URL
                // 404s the editor simply skips it.
                'plugins' => [
                    'autostart' => [self::PRESENCE_PLUGIN_GUID],
                    'pluginsData' => [
                        $settings['server_url'] . '/sdkjs-plugins/flowone-presence/config.json',
                    ],
                ],
            ],
        ];

        $config['token'] = JWT::encode($config, $settings['jwt_secret'], 'HS256');

        return $config;
    }

    // =========================================================================
    // Save callback
    // =========================================================================

    /**
     * Verify the JWT the Document Server attaches to callback requests.
     * Returns the trusted payload (the callback body) or null when invalid.
     */
    public function verifyCallbackJwt(array $body, ?string $authHeader): ?array
    {
        $secret = $this->getSettings()['jwt_secret'];
        if ($secret === '') {
            return null;
        }

        $jwt = null;
        if (!empty($body['token']) && is_string($body['token'])) {
            $jwt = $body['token'];
        } elseif ($authHeader && preg_match('/Bearer\s+(.*)$/i', $authHeader, $m)) {
            $jwt = $m[1];
        }
        if (!$jwt) {
            return null;
        }

        try {
            $decoded = json_decode((string)json_encode(JWT::decode($jwt, new Key($secret, 'HS256'))), true);
        } catch (\Throwable $e) {
            error_log('[Office] Callback JWT verification failed: ' . $e->getMessage());
            return null;
        }

        // Header-style JWTs wrap the body in a "payload" claim.
        if (isset($decoded['payload']) && is_array($decoded['payload'])) {
            return $decoded['payload'];
        }
        return $decoded;
    }

    /**
     * Handle a Document Server callback for a file.
     *
     * Status meanings: 1 editing, 2 ready-for-save (everyone closed),
     * 3 save error, 4 closed without changes, 6 force-save, 7 force-save error.
     *
     * @return array ['error' => 0] style response body for the DS
     */
    public function handleCallback(int $fileId, array $payload, DriveService $driveService): array
    {
        $status = (int)($payload['status'] ?? 0);
        $key = (string)($payload['key'] ?? '');

        if ($key !== '' && !$this->keyMatchesFile($key, $fileId)) {
            error_log("[Office] Callback key mismatch: key={$key} fileId={$fileId}");
            return ['error' => 1];
        }

        if ($status !== 2 && $status !== 6) {
            if ($status === 3 || $status === 7) {
                error_log("[Office] Document Server reported save error (status={$status}) for file {$fileId}");
            }
            return ['error' => 0];
        }

        $url = (string)($payload['url'] ?? '');
        if ($url === '') {
            error_log("[Office] Callback status {$status} without download url for file {$fileId}");
            return ['error' => 1];
        }

        $file = $driveService->getFileByIdWithPath($fileId);
        if (!$file) {
            error_log("[Office] Callback for unknown file {$fileId}");
            return ['error' => 1];
        }

        $tmpPath = $this->downloadToTemp($url);
        if ($tmpPath === null) {
            error_log("[Office] Failed to download edited document from DS for file {$fileId}: {$url}");
            return ['error' => 1];
        }

        try {
            $modifiedBy = $this->extractCallbackUser($payload);
            $updated = $driveService->updateFileContent($file['user_email'], $fileId, $tmpPath, true);
            if (!$updated) {
                error_log("[Office] updateFileContent failed for file {$fileId}");
                return ['error' => 1];
            }
            if ($modifiedBy) {
                try {
                    $this->db->prepare('UPDATE drive_files SET last_modified_by = ? WHERE id = ?')
                        ->execute([$modifiedBy, $fileId]);
                } catch (\Throwable $e) {
                    // Non-fatal bookkeeping
                }
            }

            // Final save (status 2): session over, next open = new key.
            if ($status === 2) {
                $this->rotateDocKey($fileId, $updated);
            } else {
                // Force-save keeps the session alive but the file row changed;
                // refresh the recorded version so the key isn't seen as stale.
                $upd = $this->db->prepare('
                    UPDATE office_editor_keys SET file_version = ?, file_updated_at = ? WHERE file_id = ?
                ');
                $upd->execute([(int)($updated['current_version'] ?? 1), $updated['updated_at'] ?? null, $fileId]);
            }

            return ['error' => 0];
        } finally {
            @unlink($tmpPath);
        }
    }

    private function extractCallbackUser(array $payload): ?string
    {
        $actions = $payload['actions'] ?? [];
        if (is_array($actions) && isset($actions[0]['userid'])) {
            return (string)$actions[0]['userid'];
        }
        $users = $payload['users'] ?? [];
        if (is_array($users) && isset($users[0])) {
            return (string)$users[0];
        }
        return null;
    }

    private function downloadToTemp(string $url): ?string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'flowone_office_');
        if ($tmpPath === false) {
            return null;
        }

        $fh = fopen($tmpPath, 'wb');
        if ($fh === false) {
            @unlink($tmpPath);
            return null;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            // The DS only ever hands out http(s) cache URLs; refuse anything else.
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $ok = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $httpCode >= 400 || filesize($tmpPath) === 0) {
            error_log("[Office] DS download failed (http={$httpCode}, err={$err})");
            @unlink($tmpPath);
            return null;
        }

        return $tmpPath;
    }

    // =========================================================================
    // Blank file creation
    // =========================================================================

    /**
     * Create an empty docx/xlsx/pptx and register it in Drive.
     *
     * @return array|null The new Drive file row
     */
    public function createBlankFile(DriveService $driveService, string $email, string $type, string $title, ?int $folderId): ?array
    {
        $type = strtolower($type);
        if (!in_array($type, self::CREATABLE_EXTENSIONS, true)) {
            return null;
        }

        $title = trim($title) !== '' ? trim($title) : 'Untitled';
        // Strip any extension the user typed; we add the canonical one.
        $title = preg_replace('/\.(docx|xlsx|pptx|doc|xls|ppt)$/i', '', $title);

        $tmpPath = tempnam(sys_get_temp_dir(), 'flowone_office_new_');
        if ($tmpPath === false) {
            return null;
        }

        try {
            switch ($type) {
                case 'docx':
                    $phpWord = new \PhpOffice\PhpWord\PhpWord();
                    $phpWord->addSection();
                    \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($tmpPath);
                    $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                    break;
                case 'xlsx':
                    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
                    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmpPath);
                    $spreadsheet->disconnectWorksheets();
                    $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                    break;
                case 'pptx':
                    $presentation = new \PhpOffice\PhpPresentation\PhpPresentation();
                    \PhpOffice\PhpPresentation\IOFactory::createWriter($presentation, 'PowerPoint2007')->save($tmpPath);
                    $mime = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
                    break;
                default:
                    return null;
            }

            $content = (string)file_get_contents($tmpPath);
            if ($content === '') {
                return null;
            }

            return $driveService->uploadFileContent($email, $title . '.' . $type, $content, $mime, $folderId);
        } catch (\Throwable $e) {
            error_log('[Office] createBlankFile failed: ' . $e->getMessage());
            return null;
        } finally {
            @unlink($tmpPath);
        }
    }
}
