<?php

namespace Webmail\Services;

use Webmail\Utils\NameSanitizer;
use Webmail\Utils\TokenRedactor;

/**
 * GuestCallService - Manages guest call tokens for one-click video call access.
 * 
 * Generates time-limited tokens that let external clients join a LiveKit room
 * without needing a portal session or any login. The token encodes all the
 * auth needed to get a LiveKit access token directly.
 */
class GuestCallService
{
    private \PDO $db;
    private array $config;
    private ?bool $hasOwnerColumns = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->db = \Webmail\Core\Database::getConnection($config);
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        try {
            $result = $this->db->query("SHOW TABLES LIKE 'guest_call_tokens'");
            if ($result->rowCount() === 0) {
                $this->db->exec("
                    CREATE TABLE IF NOT EXISTS guest_call_tokens (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        token VARCHAR(128) NOT NULL UNIQUE,
                        portal_call_id INT NULL,
                        room_name VARCHAR(255) NOT NULL,
                        client_id INT NULL,
                        guest_name VARCHAR(255) NULL,
                        guest_email VARCHAR(255) NULL,
                        created_by VARCHAR(255) NOT NULL,
                        expires_at DATETIME NOT NULL,
                        used_at DATETIME NULL,
                        use_count INT NOT NULL DEFAULT 0,
                        max_uses INT NOT NULL DEFAULT 0,
                        status ENUM('active', 'expired', 'revoked') NOT NULL DEFAULT 'active',
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_token (token),
                        INDEX idx_room (room_name),
                        INDEX idx_status_expires (status, expires_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                ");
                error_log("GuestCallService: Created guest_call_tokens table");
            }
        } catch (\PDOException $e) {
            error_log('GuestCallService: Table creation failed: ' . TokenRedactor::redactUrl($e->getMessage()));
        }
    }

    private function ownerColumnsPresent(): bool
    {
        if ($this->hasOwnerColumns !== null) {
            return $this->hasOwnerColumns;
        }
        try {
            $c = $this->db->query("SHOW COLUMNS FROM guest_call_tokens LIKE 'owner_type'");
            $this->hasOwnerColumns = $c && $c->rowCount() > 0;
        } catch (\Throwable $e) {
            $this->hasOwnerColumns = false;
        }
        return $this->hasOwnerColumns;
    }

    public function hasPolymorphicOwnerColumns(): bool
    {
        return $this->ownerColumnsPresent();
    }

    /** @var array<string, bool> */
    private array $calendarColumnCache = [];

    public function calendarEventsColumnExists(string $column): bool
    {
        if (isset($this->calendarColumnCache[$column])) {
            return $this->calendarColumnCache[$column];
        }
        try {
            $s = $this->db->prepare('
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ');
            $s->execute(['calendar_events', $column]);
            $this->calendarColumnCache[$column] = (int) $s->fetchColumn() > 0;
        } catch (\Throwable $e) {
            $this->calendarColumnCache[$column] = false;
        }
        return $this->calendarColumnCache[$column];
    }

    public function getDefaultCalendarRoomName(int $eventId): string
    {
        return $this->appEnv() . '_cal_' . $eventId;
    }

    /**
     * TTL in seconds: join window until min(event_start + 4h, now + 30d), at least 1h.
     */
    public function computeCalendarMeetingTtlSeconds(string $eventStartMysql): int
    {
        try {
            $start = new \DateTimeImmutable($eventStartMysql, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            $ts = strtotime($eventStartMysql . ' UTC') ?: time();
            $start = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone('UTC'));
        }
        $cap = $start->modify('+4 hours');
        $maxWall = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+30 days');
        $expiresTs = min($cap->getTimestamp(), $maxWall->getTimestamp());
        $ttl = $expiresTs - time();

        return max(3600, $ttl);
    }

    /**
     * Absolute UTC expiry for guest_call_tokens.expires_at (same window as TTL helper).
     */
    public function calendarGuestExpiryUtcMysql(string $eventStartMysql): string
    {
        $ttl = $this->computeCalendarMeetingTtlSeconds($eventStartMysql);

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . $ttl . ' seconds')
            ->format('Y-m-d H:i:s');
    }

    public function getGuestTokenForCalendarEvent(int $eventId): ?string
    {
        if (!$this->ownerColumnsPresent()) {
            return null;
        }
        $stmt = $this->db->prepare('
            SELECT token FROM guest_call_tokens
            WHERE owner_type = ? AND owner_id = ? AND role = ? AND status = ? AND expires_at > UTC_TIMESTAMP()
            ORDER BY id ASC LIMIT 1
        ');
        $stmt->execute(['calendar_event', $eventId, 'guest', 'active']);
        $t = $stmt->fetchColumn();

        return $t ? (string) $t : null;
    }

    /**
     * Ensure deterministic room name on the event, then idempotent guest+admin tokens.
     *
     * @return array{guest: array, admin: array, room_name: string, expires_at: string}
     */
    public function ensureCalendarMeetingAndGetUrls(
        int $eventId,
        string $createdByEmail,
        string $eventStartMysql,
        bool $waitingRoom = false,
        bool $participantsHidden = false,
        array $metadata = []
    ): array {
        $title = null;
        $existingRoom = null;
        if ($this->calendarEventsColumnExists('meeting_room_name')) {
            $st = $this->db->prepare('SELECT meeting_room_name, title FROM calendar_events WHERE id = ?');
            $st->execute([$eventId]);
            $row = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
            $existingRoom = $row['meeting_room_name'] ?? null;
            $title = $row['title'] ?? null;
        }
        $roomName = (is_string($existingRoom) && $existingRoom !== '') ? $existingRoom : $this->getDefaultCalendarRoomName($eventId);
        if ($this->calendarEventsColumnExists('meeting_room_name')
            && (!is_string($existingRoom) || $existingRoom === '')) {
            $u = $this->db->prepare('UPDATE calendar_events SET meeting_room_name = ? WHERE id = ?');
            $u->execute([$roomName, $eventId]);
        }
        if ($title && empty($metadata['title'])) {
            $metadata['title'] = $title;
        }
        $ttl = $this->computeCalendarMeetingTtlSeconds($eventStartMysql);

        return $this->createCalendarMeetingTokens($eventId, $roomName, $createdByEmail, $ttl, $waitingRoom, $participantsHidden, $metadata);
    }

    private function appEnv(): string
    {
        $e = $this->config['app']['env'] ?? getenv('APP_ENV');
        $e = is_string($e) && $e !== '' ? $e : 'prod';
        return preg_replace('/[^a-z0-9_-]/i', '', $e) ?: 'prod';
    }

    /**
     * Create a guest call token for an existing portal call room.
     *
     * @param int    $portalCallId  The portal_calls.id
     * @param string $roomName      The LiveKit room name
     * @param int    $clientId      The client ID
     * @param string $createdBy     Email of the CRM user who created the link
     * @param int    $ttlHours      Hours until the token expires (default 24)
     * @param int    $maxUses       Max join count (0 = unlimited)
     * @param string $role          'admin' for CRM user, 'guest' for clients
     * @return array { token, link, expires_at }
     */
    public function createToken(
        int $portalCallId,
        string $roomName,
        int $clientId,
        string $createdBy,
        int $ttlHours = 24,
        int $maxUses = 0,
        string $role = 'guest'
    ): array {
        $this->ensureRoleColumn();

        $existing = $this->db->prepare('
            SELECT token, expires_at FROM guest_call_tokens
            WHERE portal_call_id = ? AND status = ? AND expires_at > UTC_TIMESTAMP() AND role = ?
            ORDER BY created_at DESC LIMIT 1
        ');
        $existing->execute([$portalCallId, 'active', $role]);
        $row = $existing->fetch();

        if ($row) {
            return [
                'token' => $row['token'],
                'link' => $this->buildGuestCallUrl($row['token']),
                'expires_at' => $row['expires_at'],
            ];
        }

        $token = bin2hex(random_bytes(32));
        // expires_at is ALWAYS stored in UTC (gmdate, never date) and ALWAYS
        // compared against UTC_TIMESTAMP() in SQL / DateTimeZone('UTC') in PHP.
        // Mixing NOW() (session tz) with UTC values made joins 404 up to
        // tz-offset hours before the real expiry.
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlHours * 3600));

        if ($this->ownerColumnsPresent()) {
            $stmt = $this->db->prepare('
                INSERT INTO guest_call_tokens
                    (token, portal_call_id, room_name, client_id, created_by, expires_at, max_uses, role, owner_type, owner_id, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
            ');
            $stmt->execute([
                $token, $portalCallId, $roomName, $clientId, $createdBy, $expiresAt, $maxUses, $role,
                'portal_call', $portalCallId,
            ]);
        } else {
            $stmt = $this->db->prepare('
                INSERT INTO guest_call_tokens (token, portal_call_id, room_name, client_id, created_by, expires_at, max_uses, role)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$token, $portalCallId, $roomName, $clientId, $createdBy, $expiresAt, $maxUses, $role]);
        }

        $link = $this->buildGuestCallUrl($token);

        return [
            'token' => $token,
            'link' => $link,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Create a standalone pair of guest + admin call tokens.
     *
     * Not tied to any conversation or portal call. The user shares the guest
     * link with anyone external and joins via the admin link themselves.
     *
     * @param string $createdBy Email of the user creating the link
     * @param int    $ttlHours  Hours until expiry (default 24)
     * @return array { guest_link, admin_link, room_name, expires_at }
     */
    public function createStandaloneGuestToken(
        string $createdBy,
        int $ttlHours = 24,
        bool $waitingRoom = false,
        bool $participantsHidden = false
    ): array {
        $this->ensureRoleColumn();

        $env = $this->appEnv();
        $roomName = $env . '_call_' . bin2hex(random_bytes(8));
        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlHours * 3600));

        $guestToken = bin2hex(random_bytes(32));
        $adminToken = bin2hex(random_bytes(32));

        if ($this->ownerColumnsPresent()) {
            $stmt = $this->db->prepare("
                INSERT INTO guest_call_tokens
                    (token, portal_call_id, room_name, client_id, created_by, expires_at, max_uses, role, owner_type, owner_id, metadata)
                VALUES (?, NULL, ?, NULL, ?, ?, 0, 'guest', 'standalone', NULL, NULL),
                       (?, NULL, ?, NULL, ?, ?, 0, 'admin', 'standalone', NULL, NULL)
            ");
            $stmt->execute([
                $guestToken, $roomName, $createdBy, $expiresAt,
                $adminToken, $roomName, $createdBy, $expiresAt,
            ]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO guest_call_tokens
                    (token, portal_call_id, room_name, client_id, created_by, expires_at, max_uses, role)
                VALUES (?, NULL, ?, NULL, ?, ?, 0, 'guest'),
                       (?, NULL, ?, NULL, ?, ?, 0, 'admin')
            ");
            $stmt->execute([
                $guestToken, $roomName, $createdBy, $expiresAt,
                $adminToken, $roomName, $createdBy, $expiresAt,
            ]);
        }

        if ($this->meetingRoomsTableExists()) {
            $mr = new MeetingRoomService($this->config);
            $mr->ensureRoom($roomName, $waitingRoom, $participantsHidden, $createdBy);
        }

        return [
            'guest_link' => $this->buildGuestCallUrl($guestToken),
            'admin_link' => $this->buildGuestCallUrl($adminToken),
            'room_name' => $roomName,
            'expires_at' => $expiresAt,
        ];
    }

    private function meetingRoomsTableExists(): bool
    {
        static $ok;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $r = $this->db->query("SHOW TABLES LIKE 'meeting_rooms'");
            $ok = $r && $r->rowCount() > 0;
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * Guest + admin tokens for a calendar meeting (same room).
     *
     * @return array{guest: array, admin: array, room_name: string, expires_at: string}
     */
    public function createCalendarMeetingTokens(
        int $eventId,
        string $roomName,
        string $createdBy,
        int $ttlSeconds,
        bool $waitingRoom,
        bool $participantsHidden,
        array $metadata = []
    ): array {
        $this->ensureRoleColumn();
        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('+' . max(1, $ttlSeconds) . ' seconds')
            ->format('Y-m-d H:i:s');
        $metaJson = json_encode($metadata, JSON_UNESCAPED_UNICODE) ?: null;

        $guestToken = bin2hex(random_bytes(32));
        $adminToken = bin2hex(random_bytes(32));

        if ($this->ownerColumnsPresent()) {
            $this->db->beginTransaction();
            try {
                $sel = $this->db->prepare('
                    SELECT token, expires_at, role FROM guest_call_tokens
                    WHERE owner_type = ? AND owner_id = ? AND status = ? AND expires_at > UTC_TIMESTAMP()
                    FOR UPDATE
                ');
                $sel->execute(['calendar_event', $eventId, 'active']);
                $rows = $sel->fetchAll(\PDO::FETCH_ASSOC);
                $guestRow = null;
                $adminRow = null;
                foreach ($rows as $r) {
                    if (($r['role'] ?? '') === 'guest') {
                        $guestRow = $r;
                    }
                    if (($r['role'] ?? '') === 'admin') {
                        $adminRow = $r;
                    }
                }
                if ($guestRow && $adminRow) {
                    $this->db->commit();
                    if ($this->meetingRoomsTableExists()) {
                        $mr = new MeetingRoomService($this->config);
                        $mr->ensureRoom($roomName, $waitingRoom, $participantsHidden, $createdBy);
                    }
                    return [
                        'guest' => ['token' => $guestRow['token'], 'link' => $this->buildGuestCallUrl($guestRow['token']), 'expires_at' => $guestRow['expires_at']],
                        'admin' => ['token' => $adminRow['token'], 'link' => $this->buildGuestCallUrl($adminRow['token']), 'expires_at' => $adminRow['expires_at']],
                        'room_name' => $roomName,
                        'expires_at' => $guestRow['expires_at'],
                    ];
                }

                $ins = $this->db->prepare('
                    INSERT INTO guest_call_tokens
                        (token, portal_call_id, room_name, client_id, created_by, expires_at, max_uses, role, owner_type, owner_id, metadata)
                    VALUES (?, NULL, ?, NULL, ?, ?, 0, ?, ?, ?, ?)
                ');
                if (!$guestRow) {
                    $ins->execute([$guestToken, $roomName, $createdBy, $expiresAt, 'guest', 'calendar_event', $eventId, $metaJson]);
                }
                if (!$adminRow) {
                    $ins->execute([$adminToken, $roomName, $createdBy, $expiresAt, 'admin', 'calendar_event', $eventId, $metaJson]);
                }
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO guest_call_tokens
                    (token, portal_call_id, room_name, client_id, created_by, expires_at, max_uses, role)
                VALUES (?, NULL, ?, NULL, ?, ?, 0, 'guest'),
                       (?, NULL, ?, NULL, ?, ?, 0, 'admin')
            ");
            $stmt->execute([
                $guestToken, $roomName, $createdBy, $expiresAt,
                $adminToken, $roomName, $createdBy, $expiresAt,
            ]);
        }

        if ($this->meetingRoomsTableExists()) {
            $mr = new MeetingRoomService($this->config);
            $mr->ensureRoom($roomName, $waitingRoom, $participantsHidden, $createdBy);
        }

        $gTok = $guestToken;
        $aTok = $adminToken;
        if ($this->ownerColumnsPresent()) {
            $q = $this->db->prepare('SELECT token, role FROM guest_call_tokens WHERE owner_type=? AND owner_id=? AND status=? ORDER BY id');
            $q->execute(['calendar_event', $eventId, 'active']);
            foreach ($q->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if ($r['role'] === 'guest') {
                    $gTok = $r['token'];
                }
                if ($r['role'] === 'admin') {
                    $aTok = $r['token'];
                }
            }
        }

        return [
            'guest' => ['token' => $gTok, 'link' => $this->buildGuestCallUrl($gTok), 'expires_at' => $expiresAt],
            'admin' => ['token' => $aTok, 'link' => $this->buildGuestCallUrl($aTok), 'expires_at' => $expiresAt],
            'room_name' => $roomName,
            'expires_at' => $expiresAt,
        ];
    }

    private function ensureRoleColumn(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM guest_call_tokens LIKE 'role'");
            if ($cols->rowCount() === 0) {
                $this->db->exec("ALTER TABLE guest_call_tokens ADD COLUMN role ENUM('admin','guest') NOT NULL DEFAULT 'guest' AFTER guest_email");
            }
        } catch (\Throwable $e) {
            error_log("GuestCallService: ensureRoleColumn failed: " . $e->getMessage());
        }
    }

    /**
     * Validate a guest token and return the call info + LiveKit token.
     *
     * @return array<string, mixed>
     */
    public function validateAndJoin(string $token, string $guestName = 'Guest', ?string $clientIp = null, ?string $userAgent = null): array
    {
        $guestName = NameSanitizer::sanitize($guestName);
        $clientIp = $clientIp ?? ($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = $userAgent ?? (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('
                SELECT * FROM guest_call_tokens
                WHERE token = ?
                FOR UPDATE
            ');
            $stmt->execute([$token]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                $this->db->rollBack();
                return ['error' => 'Invalid or expired link', 'code' => 'invalid'];
            }

            $tokenStatus = (string) ($row['status'] ?? 'active');
            if ($tokenStatus !== 'active') {
                $this->db->rollBack();
                if ($tokenStatus === 'revoked') {
                    return ['error' => 'This link has been revoked', 'code' => 'expired'];
                }
                return ['error' => 'This link has expired', 'code' => 'expired'];
            }

            $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $exp = new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC'));
            if ($exp->getTimestamp() < $nowUtc->getTimestamp()) {
                $this->db->prepare('UPDATE guest_call_tokens SET status = ? WHERE id = ?')->execute(['expired', $row['id']]);
                $this->db->commit();
                return ['error' => 'This link has expired', 'code' => 'expired'];
            }

            if ($row['max_uses'] > 0 && (int) $row['use_count'] >= (int) $row['max_uses']) {
                $this->db->rollBack();
                return ['error' => 'This link has reached its maximum uses', 'code' => 'max_uses'];
            }

            $role = $row['role'] ?? 'guest';
            $isAdmin = $role === 'admin';
            $roomName = $row['room_name'];

            $settings = null;
            if ($this->meetingRoomsTableExists()) {
                $mr = new MeetingRoomService($this->config);
                $settings = $mr->getSettings($roomName);
            }

            if ($settings && !empty($settings['waiting_room_enabled']) && !$isAdmin) {
                $existingPending = $this->findPendingAdmissionId($token, $roomName);
                if ($existingPending !== null) {
                    $this->db->commit();
                    return [
                        'status' => 'pending_admission',
                        'request_id' => $existingPending,
                        'poll_path' => '/guest/call/' . $token . '/admission/' . $existingPending,
                    ];
                }
                $mr = new MeetingRoomService($this->config);
                $reqId = $mr->createAdmissionRequest($token, $roomName, $guestName, $clientIp ?: null);
                try {
                    $admin = new LiveKitAdminService($this->config);
                    $idents = $this->listActiveAdminIdentities($roomName);
                    if ($idents !== []) {
                        $admin->sendData(
                            $roomName,
                            json_encode(['kind' => 'admission_request', 'request_id' => $reqId, 'name' => $guestName], JSON_UNESCAPED_UNICODE),
                            $idents
                        );
                    }
                } catch (\Throwable $e) {
                    error_log('GuestCallService: SendData admission: ' . TokenRedactor::redactException($e));
                }
                $this->db->commit();
                return [
                    'status' => 'pending_admission',
                    'request_id' => $reqId,
                    'poll_path' => '/guest/call/' . $token . '/admission/' . $reqId,
                ];
            }

            $canPublish = true;
            $extraGrants = [];
            $workshopMode = $settings && !empty($settings['participants_hidden']);
            if ($workshopMode && !$isAdmin) {
                $canPublish = false;
                $extraGrants = [
                    'hidden' => true,
                    'canSubscribe' => true,
                    'canPublishData' => false,
                ];
            }
            // Workshop-mode admins need roomAdmin to subscribe to hidden
            // participants and remain able to moderate them. Without this the
            // host cannot see guests at all.
            if ($workshopMode && $isAdmin) {
                $extraGrants = array_merge($extraGrants, [
                    'roomAdmin' => true,
                    'canSubscribe' => true,
                ]);
            }

            $callService = new CallService($this->config);
            $micro = substr(str_replace('.', '', (string) microtime(true)), -6);
            $identity = $isAdmin
                ? 'admin_' . substr(hash('sha256', ($row['created_by'] ?? '') . random_bytes(8)), 0, 24)
                : 'guest_' . substr($token, 0, 8) . '_' . bin2hex(random_bytes(6)) . '_' . $micro;

            $livekitResult = $callService->getLiveKitToken(
                $roomName,
                $identity,
                $guestName,
                $canPublish,
                $extraGrants
            );

            $upd = $this->db->prepare('
                UPDATE guest_call_tokens
                SET use_count = use_count + 1,
                    used_at = COALESCE(used_at, UTC_TIMESTAMP()),
                    guest_name = ?,
                    last_used_ip = ?,
                    last_used_user_agent = ?
                WHERE id = ? AND status = ? AND expires_at > UTC_TIMESTAMP()
                  AND (max_uses = 0 OR use_count < max_uses)
            ');
            $upd->execute([$guestName, $clientIp ?: null, $userAgent ?: null, $row['id'], 'active']);
            if ($upd->rowCount() === 0) {
                $this->db->rollBack();
                error_log(sprintf(
                    'GuestCallService: join guard rejected token id=%d status=%s expires_at=%s use_count=%s max_uses=%s (utc_now=%s)',
                    (int) $row['id'],
                    (string) ($row['status'] ?? ''),
                    (string) ($row['expires_at'] ?? ''),
                    (string) ($row['use_count'] ?? ''),
                    (string) ($row['max_uses'] ?? ''),
                    gmdate('Y-m-d H:i:s')
                ));
                return ['error' => 'This link is no longer valid', 'code' => 'invalid'];
            }

            if ($this->meetingSessionsTableExists()) {
                $ins = $this->db->prepare('
                    INSERT INTO meeting_sessions (token, room_name, identity, display_name, ip, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $ins->execute([$token, $roomName, $identity, $guestName, $clientIp ?: null, $userAgent ?: null]);
            }

            $this->db->commit();

            return [
                'success' => true,
                'room_name' => $roomName,
                'livekit_token' => $livekitResult['token'],
                'ws_url' => $livekitResult['ws_url'],
                'is_admin' => $isAdmin,
                'identity' => $identity,
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            error_log('GuestCallService: LiveKit token error: ' . TokenRedactor::redactException($e));
            return ['error' => 'Failed to connect to call service', 'code' => 'service_error'];
        }
    }

    private function meetingSessionsTableExists(): bool
    {
        static $ok;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $r = $this->db->query("SHOW TABLES LIKE 'meeting_sessions'");
            $ok = $r && $r->rowCount() > 0;
        } catch (\Throwable $e) {
            $ok = false;
        }
        return $ok;
    }

    /**
     * @return string[]
     */
    private function listActiveAdminIdentities(string $roomName): array
    {
        if (!$this->meetingSessionsTableExists()) {
            return [];
        }
        $stmt = $this->db->prepare('
            SELECT DISTINCT ms.identity
            FROM meeting_sessions ms
            INNER JOIN guest_call_tokens t ON t.token = ms.token AND t.role = ? AND t.status = ?
            WHERE ms.room_name = ? AND ms.left_at IS NULL
        ');
        $stmt->execute(['admin', 'active', $roomName]);
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $id) {
            if (is_string($id) && $id !== '') {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Get token info without joining (for the pre-join screen).
     *
     * @return array<string, mixed>|null
     */
    public function getTokenInfo(string $token): ?array
    {
        $stmt = $this->db->prepare('
            SELECT gct.*, pc.status as call_status, pc.call_type
            FROM guest_call_tokens gct
            LEFT JOIN portal_calls pc ON pc.id = gct.portal_call_id
            WHERE gct.token = ?
        ');
        $stmt->execute([$token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $nowUtc = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $exp = new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC'));
        $expired = $row['status'] !== 'active' || $exp->getTimestamp() < $nowUtc->getTimestamp();
        $maxedOut = $row['max_uses'] > 0 && (int) $row['use_count'] >= (int) $row['max_uses'];

        $waiting = false;
        $hidden = false;
        if ($this->meetingRoomsTableExists()) {
            $m = $this->db->prepare('SELECT waiting_room_enabled, participants_hidden FROM meeting_rooms WHERE room_name = ? LIMIT 1');
            $m->execute([$row['room_name']]);
            $mr = $m->fetch(\PDO::FETCH_ASSOC);
            if ($mr) {
                $waiting = (bool) $mr['waiting_room_enabled'];
                $hidden = (bool) $mr['participants_hidden'];
            }
        }

        $ownerContext = [];
        if ($this->ownerColumnsPresent()
            && ($row['owner_type'] ?? '') === 'calendar_event'
            && !empty($row['owner_id'])) {
            $ce = $this->db->prepare('
                SELECT e.title, e.start_time, e.end_time, c.user_email AS organizer_email
                FROM calendar_events e
                JOIN calendars c ON e.calendar_id = c.id
                WHERE e.id = ?
            ');
            $ce->execute([(int) $row['owner_id']]);
            $ev = $ce->fetch(\PDO::FETCH_ASSOC);
            if ($ev) {
                $ownerContext = [
                    'title' => $ev['title'] ?? '',
                    'start_time' => $ev['start_time'] ?? null,
                    'end_time' => $ev['end_time'] ?? null,
                    'organizer_email' => $ev['organizer_email'] ?? null,
                ];
            }
        }
        if (($row['owner_type'] ?? '') === 'portal_call') {
            $ownerContext = [
                'call_type' => $row['call_type'] ?? 'instant',
                'call_status' => $row['call_status'] ?? 'unknown',
            ];
        }

        return [
            'valid' => !$expired && !$maxedOut,
            'expired' => $expired,
            'room_name' => $row['room_name'],
            'call_status' => $row['call_status'] ?? 'unknown',
            'call_type' => $row['call_type'] ?? 'instant',
            'created_by' => $row['created_by'],
            'expires_at' => $row['expires_at'],
            'role' => $row['role'] ?? 'guest',
            'is_admin' => ($row['role'] ?? 'guest') === 'admin',
            'source' => $row['owner_type'] ?? 'standalone',
            'waiting_room_enabled' => $waiting,
            'participants_hidden' => $hidden,
            'owner_context' => $ownerContext,
        ];
    }

    /**
     * List active standalone call links created by a specific user.
     * Returns guest-role tokens only (admin tokens are internal).
     */
    public function listUserLinks(string $createdBy): array
    {
        $ownerSql = $this->ownerColumnsPresent()
            ? '(gct.portal_call_id IS NULL AND gct.owner_type = \'standalone\')'
            : 'gct.portal_call_id IS NULL';

        $stmt = $this->db->prepare("
            SELECT gct.token, gct.room_name, gct.created_at, gct.expires_at, gct.use_count
            FROM guest_call_tokens gct
            WHERE gct.created_by = ?
              AND ($ownerSql)
              AND gct.role = 'guest'
              AND gct.status = 'active'
            ORDER BY gct.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$createdBy]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $now = time();
        return array_map(function ($row) use ($now) {
            // expires_at is stored in UTC; force UTC parsing regardless of PHP tz.
            $expired = strtotime($row['expires_at'] . ' UTC') < $now;
            return [
                'token' => $row['token'],
                'link' => $this->buildGuestCallUrl($row['token']),
                'room_name' => $row['room_name'],
                'created_at' => $row['created_at'],
                'expires_at' => $row['expires_at'],
                'use_count' => (int) $row['use_count'],
                'expired' => $expired,
            ];
        }, $rows);
    }

    /**
     * Revoke all active tokens for a LiveKit room (calendar cancel / delete).
     * INTERNAL: trusts the caller to have verified authority. Public callers
     * MUST use {@see revokeRoomByAdminToken()} instead.
     */
    public function revokeRoomEntirely(string $token): bool
    {
        $stmt = $this->db->prepare('SELECT room_name FROM guest_call_tokens WHERE token = ?');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            return false;
        }

        $this->kickLiveKitParticipantsInRoom((string) $row['room_name']);

        $revoke = $this->db->prepare("
            UPDATE guest_call_tokens SET status = 'revoked'
            WHERE room_name = ? AND status = 'active'
        ");
        $revoke->execute([$row['room_name']]);
        return $revoke->rowCount() > 0;
    }

    /**
     * Public-facing room revocation. Requires an active admin token; rejects
     * guest tokens so guests cannot nuke their own room for everyone else.
     *
     * @return array{success: bool, unauthorized?: bool, not_found?: bool, error?: string}
     */
    public function revokeRoomByAdminToken(string $adminToken): array
    {
        if ($adminToken === '') {
            return ['success' => false, 'error' => 'admin_token is required'];
        }
        $stmt = $this->db->prepare('SELECT role, status FROM guest_call_tokens WHERE token = ?');
        $stmt->execute([$adminToken]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'not_found' => true, 'error' => 'Token not found'];
        }
        if (($row['status'] ?? '') !== 'active') {
            return ['success' => false, 'unauthorized' => true, 'error' => 'Token is not active'];
        }
        if (($row['role'] ?? '') !== 'admin') {
            return ['success' => false, 'unauthorized' => true, 'error' => 'Admin token required'];
        }
        $ok = $this->revokeRoomEntirely($adminToken);
        return ['success' => $ok];
    }

    /** @deprecated Use revokeRoomEntirely */
    public function revokeToken(string $token): bool
    {
        return $this->revokeRoomEntirely($token);
    }

    public function revokeByToken(string $token): bool
    {
        $stmt = $this->db->prepare('SELECT id, room_name, token FROM guest_call_tokens WHERE token = ? AND status = ?');
        $stmt->execute([$token, 'active']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $this->db->prepare("UPDATE guest_call_tokens SET status = 'revoked' WHERE id = ?")->execute([$row['id']]);
        $this->kickLiveKitSessionsForToken((string) $row['token'], (string) $row['room_name']);
        return true;
    }

    /**
     * Kick all LiveKit sessions for a specific token (used after revokeByToken).
     */
    private function kickLiveKitSessionsForToken(string $token, string $roomName): void
    {
        if (!$this->meetingSessionsTableExists()) {
            return;
        }
        try {
            $lk = new LiveKitAdminService($this->config);
            $stmt = $this->db->prepare('SELECT DISTINCT identity FROM meeting_sessions WHERE token = ? AND room_name = ? AND left_at IS NULL');
            $stmt->execute([$token, $roomName]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $identity) {
                if (is_string($identity) && $identity !== '') {
                    $lk->removeParticipant($roomName, $identity);
                }
            }
            $this->db->prepare('UPDATE meeting_sessions SET left_at = NOW() WHERE token = ? AND room_name = ? AND left_at IS NULL')
                ->execute([$token, $roomName]);
        } catch (\Throwable $e) {
            error_log('GuestCallService kickByToken: ' . TokenRedactor::redactException($e));
        }
    }

    /**
     * Admin action: remove a specific participant by their LiveKit identity, revoke their guest token,
     * and end their session(s). Returns true on success.
     */
    public function kickParticipantByIdentity(string $adminToken, string $identity): array
    {
        if ($adminToken === '' || $identity === '') {
            return ['success' => false, 'error' => 'admin_token and identity are required'];
        }
        $stmt = $this->db->prepare('SELECT * FROM guest_call_tokens WHERE token = ? AND status = ?');
        $stmt->execute([$adminToken, 'active']);
        $adminRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$adminRow || ($adminRow['role'] ?? '') !== 'admin') {
            return ['success' => false, 'error' => 'Unauthorized'];
        }
        $roomName = (string) ($adminRow['room_name'] ?? '');

        $guestToken = null;
        if ($this->meetingSessionsTableExists()) {
            $find = $this->db->prepare('SELECT token FROM meeting_sessions WHERE room_name = ? AND identity = ? ORDER BY id DESC LIMIT 1');
            $find->execute([$roomName, $identity]);
            $r = $find->fetch(\PDO::FETCH_ASSOC);
            $guestToken = $r['token'] ?? null;
        }
        if ($guestToken && $guestToken !== $adminToken) {
            $this->revokeByToken((string) $guestToken);
        } else {
            try {
                $lk = new LiveKitAdminService($this->config);
                $lk->removeParticipant($roomName, $identity);
                if ($this->meetingSessionsTableExists()) {
                    $this->db->prepare('UPDATE meeting_sessions SET left_at = NOW() WHERE room_name = ? AND identity = ? AND left_at IS NULL')
                        ->execute([$roomName, $identity]);
                }
            } catch (\Throwable $e) {
                error_log('GuestCallService kickIdentity: ' . TokenRedactor::redactException($e));
                return ['success' => false, 'error' => 'Kick failed'];
            }
        }
        return ['success' => true];
    }

    public function revokeTokensForCalendarEvent(int $eventId): void
    {
        if (!$this->ownerColumnsPresent()) {
            return;
        }
        $stmt = $this->db->prepare('SELECT DISTINCT room_name FROM guest_call_tokens WHERE owner_type = ? AND owner_id = ? AND status = ?');
        $stmt->execute(['calendar_event', $eventId, 'active']);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $room) {
            $this->kickLiveKitParticipantsInRoom((string) $room);
        }
        $this->db->prepare("
            UPDATE guest_call_tokens SET status = 'revoked'
            WHERE owner_type = 'calendar_event' AND owner_id = ? AND status = 'active'
        ")->execute([$eventId]);
    }

    public function extendTokensTtlForCalendarEvent(int $eventId, string $newExpiresAtMysqlUtc): void
    {
        if (!$this->ownerColumnsPresent()) {
            return;
        }
        $this->db->prepare('
            UPDATE guest_call_tokens
            SET expires_at = GREATEST(expires_at, ?)
            WHERE owner_type = ? AND owner_id = ? AND status = ?
        ')->execute([$newExpiresAtMysqlUtc, 'calendar_event', $eventId, 'active']);
    }

    private function kickLiveKitParticipantsInRoom(string $roomName): void
    {
        if (!$this->meetingSessionsTableExists()) {
            return;
        }
        try {
            $lk = new LiveKitAdminService($this->config);
            $stmt = $this->db->prepare('SELECT DISTINCT identity FROM meeting_sessions WHERE room_name = ? AND left_at IS NULL');
            $stmt->execute([$roomName]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [] as $identity) {
                if (is_string($identity) && $identity !== '') {
                    $lk->removeParticipant($roomName, $identity);
                }
            }
            $this->db->prepare('UPDATE meeting_sessions SET left_at = NOW() WHERE room_name = ? AND left_at IS NULL')->execute([$roomName]);
        } catch (\Throwable $e) {
            error_log('GuestCallService kickLiveKit: ' . TokenRedactor::redactException($e));
        }
    }

    public function findPendingAdmissionId(string $guestToken, string $roomName): ?int
    {
        if (!$this->meetingRoomsTableExists()) {
            return null;
        }
        $stmt = $this->db->prepare('
            SELECT id FROM meeting_admission_requests
            WHERE token = ? AND room_name = ? AND status = ?
            ORDER BY id DESC LIMIT 1
        ');
        $stmt->execute([$guestToken, $roomName, 'pending']);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAdmissionStatus(int $requestId, string $guestToken): ?array
    {
        if (!$this->meetingRoomsTableExists()) {
            return null;
        }
        $mr = new MeetingRoomService($this->config);
        $row = $mr->getAdmissionRequest($requestId, $guestToken);
        if (!$row) {
            return null;
        }
        return [
            'status' => $row['status'],
            'livekit_token' => $row['livekit_token'],
            'livekit_ws_url' => $row['livekit_ws_url'],
        ];
    }

    /**
     * @return array{success: bool, error?: string}
     */
    public function approveAdmission(int $requestId, string $adminMagicToken): array
    {
        $stmt = $this->db->prepare('SELECT * FROM guest_call_tokens WHERE token = ? AND status = ?');
        $stmt->execute([$adminMagicToken, 'active']);
        $adminRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$adminRow || ($adminRow['role'] ?? '') !== 'admin') {
            return ['success' => false, 'error' => 'Invalid admin token'];
        }
        $mr = new MeetingRoomService($this->config);
        $req = $mr->getAdmissionRequestById($requestId);
        if (!$req || ($req['status'] ?? '') !== 'pending') {
            return ['success' => false, 'error' => 'Request not found or already resolved'];
        }
        if (($req['room_name'] ?? '') !== ($adminRow['room_name'] ?? '')) {
            return ['success' => false, 'error' => 'Room mismatch'];
        }
        $guestToken = (string) ($req['token'] ?? '');
        $guestName = NameSanitizer::sanitize((string) ($req['guest_name'] ?? 'Guest'));
        $stmt = $this->db->prepare('SELECT * FROM guest_call_tokens WHERE token = ? AND status = ?');
        $stmt->execute([$guestToken, 'active']);
        $guestTokRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$guestTokRow) {
            return ['success' => false, 'error' => 'Guest token invalid'];
        }
        $roomName = $guestTokRow['room_name'];
        $settings = $mr->getSettings($roomName);
        $canPublish = true;
        $extra = [];
        if ($settings && !empty($settings['participants_hidden'])) {
            $canPublish = false;
            $extra = ['hidden' => true, 'canSubscribe' => true, 'canPublishData' => false];
        }
        $micro = substr(str_replace('.', '', (string) microtime(true)), -6);
        $identity = 'guest_' . substr($guestToken, 0, 8) . '_' . bin2hex(random_bytes(6)) . '_' . $micro;
        try {
            $call = new CallService($this->config);
            $lk = $call->getLiveKitToken($roomName, $identity, $guestName, $canPublish, $extra);
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'LiveKit error'];
        }
        $ok = $mr->approveAdmission($requestId, $adminRow['created_by'] ?? 'admin', $lk['token'], $lk['ws_url']);
        if (!$ok) {
            return ['success' => false, 'error' => 'Already processed'];
        }
        if ($this->meetingSessionsTableExists()) {
            $ins = $this->db->prepare('
                INSERT INTO meeting_sessions (token, room_name, identity, display_name, ip, user_agent)
                VALUES (?, ?, ?, ?, NULL, NULL)
            ');
            $ins->execute([$guestToken, $roomName, $identity, $guestName]);
        }
        $this->db->prepare('
            UPDATE guest_call_tokens SET use_count = use_count + 1, used_at = COALESCE(used_at, UTC_TIMESTAMP()), guest_name = ?
            WHERE token = ? AND status = ?
        ')->execute([$guestName, $guestToken, 'active']);

        $this->notifyAdminsAdmissionResolved($roomName, $requestId, 'approved', $guestName);
        return ['success' => true];
    }

    public function denyAdmission(int $requestId, string $adminMagicToken): array
    {
        $stmt = $this->db->prepare('SELECT * FROM guest_call_tokens WHERE token = ? AND status = ?');
        $stmt->execute([$adminMagicToken, 'active']);
        $adminRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$adminRow || ($adminRow['role'] ?? '') !== 'admin') {
            return ['success' => false, 'error' => 'Invalid admin token'];
        }
        $mr = new MeetingRoomService($this->config);
        $req = $mr->getAdmissionRequestById($requestId);
        if (!$req || ($req['room_name'] ?? '') !== ($adminRow['room_name'] ?? '')) {
            return ['success' => false, 'error' => 'Request not found'];
        }
        $ok = $mr->denyAdmission($requestId, $adminRow['created_by'] ?? 'admin');
        if ($ok) {
            $guestName = NameSanitizer::sanitize((string) ($req['guest_name'] ?? 'Guest'));
            $this->notifyAdminsAdmissionResolved((string) ($req['room_name'] ?? ''), $requestId, 'denied', $guestName);
        }
        return $ok ? ['success' => true] : ['success' => false, 'error' => 'Already processed'];
    }

    /**
     * Push an admission_resolved event to active admin participants in the room,
     * so other admin tabs/devices remove the request from their pending lists in real-time.
     */
    private function notifyAdminsAdmissionResolved(string $roomName, int $requestId, string $action, string $guestName): void
    {
        if ($roomName === '') {
            return;
        }
        try {
            $idents = $this->listActiveAdminIdentities($roomName);
            if ($idents === []) {
                return;
            }
            $lk = new LiveKitAdminService($this->config);
            $lk->sendData(
                $roomName,
                json_encode([
                    'kind' => 'admission_resolved',
                    'request_id' => $requestId,
                    'action' => $action,
                    'name' => $guestName,
                ], JSON_UNESCAPED_UNICODE),
                $idents
            );
        } catch (\Throwable $e) {
            error_log('GuestCallService: SendData admission_resolved: ' . TokenRedactor::redactException($e));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAdmissionLobby(string $adminMagicToken): array
    {
        $stmt = $this->db->prepare('SELECT * FROM guest_call_tokens WHERE token = ? AND status = ?');
        $stmt->execute([$adminMagicToken, 'active']);
        $adminRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$adminRow || ($adminRow['role'] ?? '') !== 'admin') {
            return [];
        }
        $mr = new MeetingRoomService($this->config);
        return $mr->listPendingForRoom((string) $adminRow['room_name']);
    }

    /**
     * Admin-only attendees list. Returns LiveKit-reported participants for the
     * admin token's room. Useful in workshop mode where guests are hidden from
     * each other but admins still need a roster.
     */
    public function listRoomAttendees(string $adminMagicToken): array
    {
        if ($adminMagicToken === '') {
            return ['success' => false, 'error' => 'admin_token is required'];
        }
        $stmt = $this->db->prepare('SELECT room_name, role FROM guest_call_tokens WHERE token = ? AND status = ?');
        $stmt->execute([$adminMagicToken, 'active']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row || ($row['role'] ?? '') !== 'admin') {
            return ['success' => false, 'error' => 'Unauthorized'];
        }
        $roomName = (string) ($row['room_name'] ?? '');
        if ($roomName === '') {
            return ['success' => true, 'participants' => []];
        }
        try {
            $lk = new LiveKitAdminService($this->config);
            $raw = $lk->listParticipants($roomName);
        } catch (\Throwable $e) {
            error_log('GuestCallService listRoomAttendees: ' . TokenRedactor::redactException($e));
            return ['success' => false, 'error' => 'LiveKit list failed'];
        }

        $out = [];
        foreach ((array) $raw as $p) {
            if (!is_array($p)) {
                continue;
            }
            $identity = (string) ($p['identity'] ?? '');
            if ($identity === '') {
                continue;
            }
            $out[] = [
                'identity' => $identity,
                'identity_short' => substr($identity, 0, 24),
                'name' => NameSanitizer::sanitize((string) ($p['name'] ?? '')),
                'joined_at' => isset($p['joined_at']) ? (int) $p['joined_at'] : null,
                'state' => $p['state'] ?? null,
            ];
        }
        return ['success' => true, 'participants' => $out];
    }

    public function sendTranscript(string $token, array $messages, int $duration): array
    {
        $stmt = $this->db->prepare('
            SELECT created_by, room_name, portal_call_id FROM guest_call_tokens WHERE token = ? LIMIT 1
        ');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if (!$row) {
            return ['error' => 'Token not found', 'code' => 'invalid'];
        }

        $adminEmail = $row['created_by'];
        if (empty($adminEmail)) {
            return ['error' => 'No admin email found'];
        }

        $portalCallId = $row['portal_call_id'] ?? null;

        // Store transcript in portal_calls for manual resend
        if ($portalCallId) {
            $this->storeTranscript($portalCallId, $messages, $duration);
        }

        return $this->buildAndSendTranscript($adminEmail, $row['room_name'], $messages, $duration);
    }

    /**
     * Resend a transcript from stored data for a portal call.
     */
    public function resendTranscript(int $portalCallId, string $adminEmail): array
    {
        $this->ensureTranscriptColumns();
        $stmt = $this->db->prepare('SELECT room_name, chat_transcript, duration_seconds FROM portal_calls WHERE id = ?');
        $stmt->execute([$portalCallId]);
        $call = $stmt->fetch();

        if (!$call || empty($call['chat_transcript'])) {
            return ['error' => 'No transcript found for this call'];
        }

        $messages = json_decode($call['chat_transcript'], true);
        if (!is_array($messages) || empty($messages)) {
            return ['error' => 'Transcript is empty'];
        }

        return $this->buildAndSendTranscript($adminEmail, $call['room_name'], $messages, (int)$call['duration_seconds']);
    }

    private function storeTranscript(int $portalCallId, array $messages, int $duration): void
    {
        $this->ensureTranscriptColumns();
        try {
            $stmt = $this->db->prepare('
                UPDATE portal_calls SET chat_transcript = ?, transcript_sent_at = NOW() WHERE id = ?
            ');
            $stmt->execute([json_encode($messages, JSON_UNESCAPED_UNICODE), $portalCallId]);
        } catch (\Throwable $e) {
            error_log("GuestCallService::storeTranscript failed: " . $e->getMessage());
        }
    }

    private function ensureTranscriptColumns(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            $cols = $this->db->query("SHOW COLUMNS FROM portal_calls LIKE 'chat_transcript'");
            if ($cols->rowCount() === 0) {
                $this->db->exec("ALTER TABLE portal_calls ADD COLUMN chat_transcript LONGTEXT NULL AFTER notes");
                $this->db->exec("ALTER TABLE portal_calls ADD COLUMN transcript_sent_at DATETIME NULL AFTER chat_transcript");
            }
        } catch (\Throwable $e) {
            error_log("GuestCallService::ensureTranscriptColumns failed: " . $e->getMessage());
        }
    }

    /** Total budget for real MIME attachments on the transcript email. */
    private const TRANSCRIPT_ATTACHMENT_BUDGET = 15 * 1024 * 1024;

    private function buildAndSendTranscript(string $adminEmail, string $roomName, array $messages, int $duration): array
    {
        $durationFormatted = sprintf('%02d:%02d:%02d',
            floor($duration / 3600),
            floor(($duration % 3600) / 60),
            $duration % 60
        );

        // Server-stored in-call attachments for this room (uploaded via the
        // chat panel). Referenced by id from `isFile` messages.
        $attachmentsById = [];
        try {
            $attService = new GuestCallAttachmentService($this->config);
            foreach ($attService->listForRoom($roomName) as $att) {
                $attachmentsById[$att['id']] = $att;
            }
        } catch (\Throwable $e) {
            error_log("GuestCallService::buildAndSendTranscript attachment lookup failed for $roomName: " . $e->getMessage());
        }

        $emailAttachments = [];   // real MIME attachments: { path, name, type }
        $inlineImages = [];       // CID-embedded images:   { content, cid, name, type }
        $attachmentBudget = self::TRANSCRIPT_ATTACHMENT_BUDGET;
        $attachedIds = [];

        $colors = ['#3b82f6','#8b5cf6','#ec4899','#f59e0b','#10b981','#06b6d4','#f97316','#6366f1'];
        $senderColors = [];
        $colorIndex = 0;

        $htmlMessages = '';
        $lastSender = '';
        foreach ($messages as $msg) {
            $time = date('H:i', (int)(($msg['ts'] ?? 0) / 1000));
            $sender = htmlspecialchars($msg['sender'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
            $text = $msg['message'] ?? '';

            if (!isset($senderColors[$sender])) {
                $senderColors[$sender] = $colors[$colorIndex % count($colors)];
                $colorIndex++;
            }
            $color = $senderColors[$sender];
            $showName = ($sender !== $lastSender);
            $lastSender = $sender;

            $nameHtml = $showName
                ? "<div style=\"font-size:13px;font-weight:600;color:$color;margin-bottom:2px;\">$sender</div>"
                : '';

            if (!empty($msg['isFile'])) {
                $attId = (int)($msg['attachmentId'] ?? 0);
                $att = $attachmentsById[$attId] ?? null;
                $fileName = htmlspecialchars((string)($msg['name'] ?? ($att['name'] ?? 'Attachment')), ENT_QUOTES, 'UTF-8');
                $fileSize = $this->formatBytes((int)($msg['size'] ?? ($att['size'] ?? 0)));

                $withinBudget = $att && (int)$att['size'] <= $attachmentBudget;
                if ($withinBudget && !empty($msg['isImage'])) {
                    // Image: CID-embed so it always displays inline
                    $content = @file_get_contents($att['path']);
                    if ($content !== false) {
                        $cid = 'gcatt' . $attId;
                        $inlineImages[] = [
                            'content' => $content,
                            'cid' => $cid,
                            'name' => $att['name'],
                            'type' => $att['mime'],
                        ];
                        $attachmentBudget -= (int)$att['size'];
                        $attachedIds[$attId] = true;
                        $contentHtml = "<img src=\"cid:$cid\" style=\"max-width:100%;max-height:300px;border-radius:8px;display:block;\" alt=\"$fileName\"/>";
                    } else {
                        $contentHtml = "&#128206; $fileName <span style=\"color:#94a3b8;\">($fileSize)</span>";
                    }
                } elseif ($withinBudget) {
                    // Non-image file: attach to the email, show a file row
                    $emailAttachments[] = [
                        'path' => $att['path'],
                        'name' => $att['name'],
                        'type' => $att['mime'],
                    ];
                    $attachmentBudget -= (int)$att['size'];
                    $attachedIds[$attId] = true;
                    $contentHtml = "&#128206; <strong>$fileName</strong> <span style=\"color:#94a3b8;\">($fileSize)</span><br><span style=\"font-size:12px;color:#64748b;\">Attached to this email</span>";
                } else {
                    $reason = $att ? 'too large to attach' : 'no longer available';
                    $contentHtml = "&#128206; <strong>$fileName</strong> <span style=\"color:#94a3b8;\">($fileSize &middot; $reason)</span>";
                }
            } elseif (!empty($msg['isImage'])) {
                // Legacy base64 data URI image — SmtpService converts data:
                // URIs to CID embeds at send time, so leave the src as-is.
                $imgSrc = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
                $contentHtml = "<img src=\"$imgSrc\" style=\"max-width:100%;max-height:300px;border-radius:8px;display:block;\" alt=\"Shared image\"/>";
            } else {
                $contentHtml = nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
                $contentHtml = preg_replace(
                    '/(https?:\/\/[^\s<]+)/',
                    '<a href="$1" style="color:#3b82f6;text-decoration:underline;">$1</a>',
                    $contentHtml
                );
            }

            $topMargin = $showName ? '12px' : '2px';
            $htmlMessages .= <<<MSG
<div style="margin-top:$topMargin;padding:0 24px;">
  $nameHtml
  <div style="display:inline-block;max-width:85%;background:#f1f5f9;border-radius:12px;padding:8px 14px;font-size:14px;line-height:1.5;color:#1e293b;">
    $contentHtml
  </div>
  <div style="font-size:11px;color:#94a3b8;margin-top:2px;padding-left:2px;">$time</div>
</div>
MSG;
        }

        // Files uploaded during the call but never referenced by a chat
        // message (shouldn't normally happen) — attach them too, within budget.
        foreach ($attachmentsById as $attId => $att) {
            if (isset($attachedIds[$attId]) || (int)$att['size'] > $attachmentBudget) {
                continue;
            }
            $emailAttachments[] = ['path' => $att['path'], 'name' => $att['name'], 'type' => $att['mime']];
            $attachmentBudget -= (int)$att['size'];
            $attachedIds[$attId] = true;
        }

        $date = date('M j, Y');
        $msgCount = count($messages);
        $participantList = implode(', ', array_keys($senderColors));
        $html = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#222;margin:0;padding:20px;background:#f0f2f5;">
<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
  <!-- Light header: many email clients (Gmail) strip gradient backgrounds,
       so never rely on a dark background for text contrast. The <font color>
       tags are a belt-and-braces fallback: HTML attributes survive even when
       a client strips style="" attributes entirely. -->
  <div style="padding:28px 28px 20px;border-bottom:1px solid #e2e8f0;" bgcolor="#ffffff">
    <div style="font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:1.5px;color:#94a3b8;margin-bottom:8px;"><font color="#94a3b8">Meeting Transcript</font></div>
    <h2 style="margin:0;font-size:22px;font-weight:700;color:#111111;"><font color="#111111">FlowOne Call</font></h2>
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:14px;font-size:13px;">
      <tr>
        <td style="padding-right:24px;"><font color="#94a3b8">Date</font><br><strong style="color:#111111;"><font color="#111111">$date</font></strong></td>
        <td style="padding-right:24px;"><font color="#94a3b8">Duration</font><br><strong style="color:#111111;"><font color="#111111">$durationFormatted</font></strong></td>
        <td><font color="#94a3b8">Messages</font><br><strong style="color:#111111;"><font color="#111111">$msgCount</font></strong></td>
      </tr>
    </table>
  </div>
  <div style="padding:8px 0 4px;border-bottom:1px solid #e2e8f0;">
    <div style="padding:8px 24px;font-size:12px;color:#64748b;">
      Participants: <strong style="color:#475569;">$participantList</strong>
    </div>
  </div>
  <div style="padding:8px 0 20px;">
    $htmlMessages
  </div>
  <div style="padding:16px 24px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;text-align:center;">
    Automatically generated by FlowOne &middot; $date
  </div>
</div>
</body></html>
HTML;

        $subject = "Call Chat Transcript - $date ($durationFormatted)";

        error_log(sprintf(
            'GuestCallService::buildAndSendTranscript room=%s to=%s messages=%d attachments=%d inline_images=%d',
            $roomName, $adminEmail, count($messages), count($emailAttachments), count($inlineImages)
        ));

        // Try sending with the user's own SMTP credentials (same as portal magic links)
        $sent = $this->sendViaUserCredentials($adminEmail, $subject, $html, $emailAttachments, $inlineImages);
        if ($sent) return ['success' => true];

        // Fallback: try system noreply credentials
        try {
            $noreplyUser = $this->config['smtp']['username'] ?? 'noreply@devcon1.hu';
            $noreplyPass = $this->config['smtp']['password'] ?? '';
            if ($noreplyPass === '') {
                error_log('GuestCallService::sendTranscript: noreply SMTP password missing in config — cannot fall back');
                return ['error' => 'Transcript email not sent: no usable SMTP credentials'];
            }
            $smtp = new SmtpService($this->config['smtp']);
            $smtp->setCredentials($noreplyUser, $noreplyPass);
            $smtp->send([
                'from_name' => 'FlowOne Calls',
                'from_email' => self::TRANSCRIPT_SENDER,
                'to' => [['email' => $adminEmail, 'name' => '']],
                'subject' => $subject,
                'body_html' => $html,
                'attachments' => $emailAttachments,
                'inline_images' => $inlineImages,
            ]);
            error_log("GuestCallService::sendTranscript sent via noreply fallback to $adminEmail");
            return ['success' => true];
        } catch (\Throwable $e) {
            error_log("GuestCallService::sendTranscript noreply fallback failed: " . $e->getMessage());
            return ['error' => 'Failed to send transcript email: ' . $e->getMessage()];
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }

    private const TRANSCRIPT_SENDER = 'robert@flowone.pro';

    /**
     * Send email via the TRANSCRIPT_SENDER account using its credentials from webmail_sessions.
     */
    private function sendViaUserCredentials(
        string $recipientEmail,
        string $subject,
        string $html,
        array $attachments = [],
        array $inlineImages = []
    ): bool {
        $senderEmail = self::TRANSCRIPT_SENDER;

        try {
            $stmt = $this->db->prepare('
                SELECT encrypted_password FROM webmail_sessions
                WHERE email = ? AND is_valid = 1 ORDER BY last_active_at DESC LIMIT 1
            ');
            $stmt->execute([$senderEmail]);
            $session = $stmt->fetch();

            if (!$session || empty($session['encrypted_password'])) {
                error_log("GuestCallService: No active session for $senderEmail, falling back to noreply");
                return false;
            }

            $sessionService = new SessionService(
                $this->config['jwt'],
                $this->config['imap_encryption_key'] ?? ''
            );
            $password = $sessionService->decryptPassword($session['encrypted_password']);

            if (!$password) {
                error_log("GuestCallService: Failed to decrypt password for $senderEmail");
                return false;
            }

            $smtp = new SmtpService($this->config['smtp']);
            $smtp->setCredentials($senderEmail, $password);
            $smtp->send([
                'from_name' => 'FlowOne Calls',
                'from_email' => $senderEmail,
                'to' => [['email' => $recipientEmail, 'name' => '']],
                'subject' => $subject,
                'body_html' => $html,
                'attachments' => $attachments,
                'inline_images' => $inlineImages,
            ]);

            error_log("GuestCallService: transcript sent via user credentials ($senderEmail -> $recipientEmail)");
            return true;
        } catch (\Throwable $e) {
            error_log("GuestCallService: sendViaUserCredentials failed ($senderEmail -> $recipientEmail): " . $e->getMessage());
            return false;
        }
    }

    private function buildGuestCallUrl(string $token): string
    {
        $baseUrl = $this->config['app']['frontend_url']
            ?? $this->config['app']['url']
            ?? '';

        if (empty($baseUrl) && !empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
        }

        return rtrim($baseUrl, '/') . '/guest/call/' . $token;
    }
}
