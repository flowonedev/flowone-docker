<?php

namespace Webmail\Services;

use Webmail\Utils\TokenRedactor;

/**
 * GuestCallAttachmentService - Server-stored attachments for in-call chat.
 *
 * Files shared during a guest video call (attach button, clipboard paste,
 * drag & drop) are uploaded here, keyed by the LiveKit room name. Any
 * participant holding a valid token for the same room can download them.
 *
 * Chat messages broadcast only the attachment id over the LiveKit data
 * channel — each client builds its download URL with its OWN token, so an
 * admin token never leaks to guests through a shared URL.
 *
 * Files are included in the transcript email when the call ends, then
 * cleaned up after GuestCallAttachmentService::RETENTION_DAYS by the
 * cleanup-stale-rooms cron.
 */
class GuestCallAttachmentService
{
    public const MAX_FILE_BYTES = 25 * 1024 * 1024;   // 25MB per file
    public const MAX_ROOM_BYTES = 100 * 1024 * 1024;  // 100MB per room
    public const RETENTION_DAYS = 7;

    private const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'exe', 'bat', 'cmd', 'com', 'scr', 'msi', 'dll',
        'sh', 'bash', 'csh', 'ksh',
        'jsp', 'jspx', 'asp', 'aspx',
        'py', 'pl', 'rb', 'cgi',
        'htaccess', 'htpasswd',
    ];

    private const BLOCKED_MIME_TYPES = [
        'application/x-httpd-php', 'application/x-php', 'text/x-php',
        'application/x-executable', 'application/x-sharedlib',
        'application/x-msdos-program', 'application/x-msdownload',
        'application/x-dosexec', 'application/bat', 'application/x-bat',
        'application/x-sh', 'application/x-csh',
        'application/java-archive', 'application/x-java-class',
    ];

    private \PDO $db;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        try {
            $result = $this->db->query("SHOW TABLES LIKE 'guest_call_attachments'");
            if ($result->rowCount() === 0) {
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS guest_call_attachments (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        room_name VARCHAR(255) NOT NULL,
                        token_id INT NULL,
                        original_name VARCHAR(255) NOT NULL,
                        stored_name VARCHAR(255) NOT NULL,
                        mime_type VARCHAR(127) NOT NULL DEFAULT 'application/octet-stream',
                        size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                        uploaded_by VARCHAR(255) NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_room (room_name),
                        INDEX idx_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                error_log('GuestCallAttachmentService: Created guest_call_attachments table');
            }
        } catch (\PDOException $e) {
            error_log('GuestCallAttachmentService: Table creation failed: ' . TokenRedactor::redactUrl($e->getMessage()));
        }
    }

    /**
     * Validate a guest call token and return its row (id, room_name) when the
     * token is active and not expired. Any participant role may share files.
     */
    public function validateToken(string $token): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, room_name FROM guest_call_tokens
            WHERE token = ? AND status = 'active' AND expires_at > UTC_TIMESTAMP()
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Store an uploaded file ($_FILES entry) for the room behind $token.
     *
     * @return array { success, attachment? } or { error, code? }
     */
    public function upload(string $token, array $file, string $uploadedBy = ''): array
    {
        $tokenRow = $this->validateToken($token);
        if (!$tokenRow) {
            return ['error' => 'Invalid or expired call link', 'code' => 'invalid_token'];
        }

        if (!isset($file['tmp_name']) || !is_readable($file['tmp_name'])) {
            return ['error' => 'No file uploaded', 'code' => 'no_file'];
        }
        if (!empty($file['error'])) {
            return ['error' => 'Upload failed (error ' . (int)$file['error'] . ')', 'code' => 'upload_error'];
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            return ['error' => 'Empty file', 'code' => 'empty_file'];
        }
        if ($size > self::MAX_FILE_BYTES) {
            return ['error' => 'File too large (max 25MB)', 'code' => 'too_large'];
        }

        $roomName = (string)$tokenRow['room_name'];
        if ($this->roomUsageBytes($roomName) + $size > self::MAX_ROOM_BYTES) {
            return ['error' => 'Storage limit for this call reached', 'code' => 'room_quota'];
        }

        $originalName = $this->sanitizeOriginalName((string)($file['name'] ?? 'file'));
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // Block dangerous extensions anywhere in the name (double-extension attacks)
        $nameParts = explode('.', strtolower($originalName));
        array_shift($nameParts);
        foreach ($nameParts as $ext) {
            if (in_array($ext, self::BLOCKED_EXTENSIONS, true)) {
                return ['error' => 'This file type is not allowed', 'code' => 'blocked_type'];
            }
        }

        // Server-side MIME detection (never trust the client-provided type)
        $mimeType = 'application/octet-stream';
        try {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $detected = $finfo->file($file['tmp_name']);
            if (is_string($detected) && $detected !== '') {
                $mimeType = $detected;
            }
        } catch (\Throwable $e) {
            // keep octet-stream fallback
        }
        if (in_array($mimeType, self::BLOCKED_MIME_TYPES, true)) {
            return ['error' => 'This file type is not allowed', 'code' => 'blocked_type'];
        }

        $dir = $this->roomDir($roomName);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            $why = error_get_last()['message'] ?? 'unknown';
            error_log("GuestCallAttachmentService: Failed to create dir $dir ($why)");
            return ['error' => 'Storage unavailable', 'code' => 'storage'];
        }

        $storedName = bin2hex(random_bytes(16)) . ($extension !== '' ? '.' . $extension : '');
        $destPath = $dir . '/' . $storedName;

        $moved = is_uploaded_file($file['tmp_name'])
            ? @move_uploaded_file($file['tmp_name'], $destPath)
            // CLI test scripts pass plain files; treat them like uploads
            : @rename($file['tmp_name'], $destPath);
        if (!$moved) {
            error_log("GuestCallAttachmentService: Failed to move upload to $destPath");
            return ['error' => 'Failed to store file', 'code' => 'storage'];
        }

        $stmt = $this->db->prepare('
            INSERT INTO guest_call_attachments
                (room_name, token_id, original_name, stored_name, mime_type, size_bytes, uploaded_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $roomName,
            (int)$tokenRow['id'],
            $originalName,
            $storedName,
            substr($mimeType, 0, 127),
            $size,
            mb_substr($uploadedBy, 0, 255),
        ]);
        $id = (int)$this->db->lastInsertId();

        return [
            'success' => true,
            'attachment' => [
                'id' => $id,
                'name' => $originalName,
                'mime' => $mimeType,
                'size' => $size,
                'is_image' => str_starts_with($mimeType, 'image/'),
            ],
        ];
    }

    /**
     * Resolve an attachment for download. Token must be valid and belong to
     * the SAME room as the attachment.
     *
     * @return array { success, path, name, mime, size } or { error, code }
     */
    public function resolveForDownload(string $token, int $attachmentId): array
    {
        $tokenRow = $this->validateToken($token);
        if (!$tokenRow) {
            return ['error' => 'Invalid or expired call link', 'code' => 'invalid_token'];
        }

        $row = $this->getAttachmentRow($attachmentId);
        if (!$row || $row['room_name'] !== $tokenRow['room_name']) {
            return ['error' => 'Attachment not found', 'code' => 'not_found'];
        }

        $path = $this->roomDir($row['room_name']) . '/' . $row['stored_name'];
        if (!is_file($path)) {
            return ['error' => 'Attachment no longer available', 'code' => 'not_found'];
        }

        return [
            'success' => true,
            'path' => $path,
            'name' => $row['original_name'],
            'mime' => $row['mime_type'],
            'size' => (int)$row['size_bytes'],
        ];
    }

    public function getAttachmentRow(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM guest_call_attachments WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * All attachments of a room with absolute file paths (for the transcript email).
     * Missing files are skipped.
     */
    public function listForRoom(string $roomName): array
    {
        $stmt = $this->db->prepare('
            SELECT id, original_name, stored_name, mime_type, size_bytes, uploaded_by
            FROM guest_call_attachments WHERE room_name = ? ORDER BY id ASC
        ');
        $stmt->execute([$roomName]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $path = $this->roomDir($roomName) . '/' . $row['stored_name'];
            if (!is_file($path)) {
                continue;
            }
            $out[] = [
                'id' => (int)$row['id'],
                'name' => $row['original_name'],
                'mime' => $row['mime_type'],
                'size' => (int)$row['size_bytes'],
                'uploaded_by' => $row['uploaded_by'],
                'path' => $path,
            ];
        }
        return $out;
    }

    /**
     * Delete attachments (rows + files) older than $days. Returns deleted count.
     * Called from the cleanup-stale-rooms cron.
     */
    public function cleanupOlderThan(int $days = self::RETENTION_DAYS): int
    {
        $stmt = $this->db->prepare('
            SELECT id, room_name, stored_name FROM guest_call_attachments
            WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)
        ');
        $stmt->execute([$days]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $deleted = 0;
        foreach ($rows as $row) {
            $dir = $this->roomDir($row['room_name']);
            @unlink($dir . '/' . $row['stored_name']);
            $del = $this->db->prepare('DELETE FROM guest_call_attachments WHERE id = ?');
            $del->execute([(int)$row['id']]);
            $deleted++;
            // Remove the room dir when it became empty
            if (is_dir($dir) && count(glob($dir . '/*') ?: []) === 0) {
                @rmdir($dir);
            }
        }
        return $deleted;
    }

    /** Delete a single attachment row + file (test cleanup helper). */
    public function deleteAttachment(int $id): bool
    {
        $row = $this->getAttachmentRow($id);
        if (!$row) {
            return false;
        }
        $dir = $this->roomDir($row['room_name']);
        @unlink($dir . '/' . $row['stored_name']);
        $del = $this->db->prepare('DELETE FROM guest_call_attachments WHERE id = ?');
        $del->execute([$id]);
        if (is_dir($dir) && count(glob($dir . '/*') ?: []) === 0) {
            @rmdir($dir);
        }
        return true;
    }

    public function baseDir(): string
    {
        // Use the configured storage root (/var/www/vps-email/storage on the
        // server) — backend/storage is not writable by the web server user.
        $root = $this->config['storage_path'] ?? dirname(__DIR__, 2) . '/storage';
        return rtrim($root, '/') . '/call-attachments';
    }

    private function roomDir(string $roomName): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $roomName) ?: 'room';
        return $this->baseDir() . '/' . $safe;
    }

    private function roomUsageBytes(string $roomName): int
    {
        $stmt = $this->db->prepare('SELECT COALESCE(SUM(size_bytes), 0) FROM guest_call_attachments WHERE room_name = ?');
        $stmt->execute([$roomName]);
        return (int)$stmt->fetchColumn();
    }

    private function sanitizeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        // Strip control chars; keep unicode letters for display
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'file';
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'file';
        }
        return mb_substr($name, 0, 255);
    }
}
