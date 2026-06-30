<?php

namespace Webmail\Services;

use Webmail\Core\Database;

/**
 * OfficeGuestLinkService - shareable guest links for OnlyOffice editing.
 *
 * Opaque random tokens stored in office_guest_tokens (same pattern as
 * guest_call_tokens). The token IS the auth: it maps to one Drive file,
 * a role (viewer/editor) and an optional expiry.
 */
class OfficeGuestLinkService
{
    private \PDO $db;

    public function __construct(array $config)
    {
        $this->db = Database::getConnection($config);
    }

    public function createLink(int $fileId, string $role, string $createdBy, ?int $expiresInHours, ?string $label): array
    {
        $role = $role === 'editor' ? 'editor' : 'viewer';
        $token = bin2hex(random_bytes(24));
        $expiresAt = $expiresInHours !== null && $expiresInHours > 0
            ? date('Y-m-d H:i:s', time() + $expiresInHours * 3600)
            : null;

        $stmt = $this->db->prepare('
            INSERT INTO office_guest_tokens (token, file_id, role, label, created_by, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$token, $fileId, $role, $label, strtolower($createdBy), $expiresAt]);

        return [
            'token' => $token,
            'file_id' => $fileId,
            'role' => $role,
            'label' => $label,
            'expires_at' => $expiresAt,
            'status' => 'active',
        ];
    }

    public function listLinks(int $fileId): array
    {
        $stmt = $this->db->prepare('
            SELECT token, file_id, role, label, created_by, expires_at, use_count, status, created_at, last_used_at
            FROM office_guest_tokens
            WHERE file_id = ? AND status = "active"
            ORDER BY created_at DESC
        ');
        $stmt->execute([$fileId]);
        $rows = $stmt->fetchAll() ?: [];

        $now = date('Y-m-d H:i:s');
        foreach ($rows as &$row) {
            $row['expired'] = $row['expires_at'] !== null && $row['expires_at'] < $now;
        }
        return $rows;
    }

    public function revokeLink(string $token, string $requestedBy): bool
    {
        $stmt = $this->db->prepare('
            UPDATE office_guest_tokens SET status = "revoked"
            WHERE token = ? AND created_by = ?
        ');
        $stmt->execute([$token, strtolower($requestedBy)]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Validate a guest token WITHOUT consuming it (no use_count bump).
     *
     * Used for follow-up requests on an already-opened link - e.g. the live
     * presence token - so a single guest open does not inflate use_count or
     * trip a max_uses limit a second time.
     */
    public function validate(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM office_guest_tokens WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active') {
            return null;
        }
        if ($row['expires_at'] !== null && $row['expires_at'] < date('Y-m-d H:i:s')) {
            return null;
        }
        if ((int)$row['max_uses'] > 0 && (int)$row['use_count'] >= (int)$row['max_uses']) {
            return null;
        }

        return $row;
    }

    /**
     * Validate a guest token and return its row, or null when the token is
     * unknown, revoked, or expired. Bumps use_count on success.
     */
    public function validateAndConsume(string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM office_guest_tokens WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row || $row['status'] !== 'active') {
            return null;
        }
        if ($row['expires_at'] !== null && $row['expires_at'] < date('Y-m-d H:i:s')) {
            return null;
        }
        if ((int)$row['max_uses'] > 0 && (int)$row['use_count'] >= (int)$row['max_uses']) {
            return null;
        }

        $this->db->prepare('
            UPDATE office_guest_tokens SET use_count = use_count + 1, last_used_at = NOW() WHERE id = ?
        ')->execute([$row['id']]);

        return $row;
    }
}
