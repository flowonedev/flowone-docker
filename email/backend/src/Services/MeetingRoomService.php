<?php

namespace Webmail\Services;

/**
 * meeting_rooms settings + meeting_admission_requests queue.
 */
class MeetingRoomService
{
    private \PDO $db;

    public function __construct(array $config)
    {
        $this->db = \Webmail\Core\Database::getConnection($config);
    }

    public function ensureRoom(string $roomName, bool $waitingRoom, bool $participantsHidden, string $createdBy): void
    {
        $stmt = $this->db->prepare('
            INSERT IGNORE INTO meeting_rooms (room_name, waiting_room_enabled, participants_hidden, created_by)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([
            $roomName,
            $waitingRoom ? 1 : 0,
            $participantsHidden ? 1 : 0,
            $createdBy,
        ]);
    }

    /**
     * Upsert room settings. Unlike ensureRoom() (INSERT IGNORE, which only
     * applies on first creation), this updates the waiting-room / workshop
     * flags on an existing room. Used when the organizer changes meeting
     * options for an event that already has a room.
     */
    public function setSettings(string $roomName, bool $waitingRoom, bool $participantsHidden, string $createdBy): void
    {
        $stmt = $this->db->prepare('
            INSERT INTO meeting_rooms (room_name, waiting_room_enabled, participants_hidden, created_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                waiting_room_enabled = VALUES(waiting_room_enabled),
                participants_hidden = VALUES(participants_hidden)
        ');
        $stmt->execute([
            $roomName,
            $waitingRoom ? 1 : 0,
            $participantsHidden ? 1 : 0,
            $createdBy,
        ]);
    }

    /**
     * @return array{waiting_room_enabled: bool, participants_hidden: bool}|null
     */
    public function getSettings(string $roomName): ?array
    {
        $stmt = $this->db->prepare('SELECT waiting_room_enabled, participants_hidden FROM meeting_rooms WHERE room_name = ? LIMIT 1');
        $stmt->execute([$roomName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'waiting_room_enabled' => (bool) $row['waiting_room_enabled'],
            'participants_hidden' => (bool) $row['participants_hidden'],
        ];
    }

    public function expireStaleRequests(): void
    {
        $this->db->exec("
            UPDATE meeting_admission_requests
            SET status = 'expired'
            WHERE status = 'pending'
              AND requested_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
        ");
    }

    public function createAdmissionRequest(string $token, string $roomName, string $guestName, ?string $guestIp): int
    {
        $this->expireStaleRequests();
        $stmt = $this->db->prepare('
            INSERT INTO meeting_admission_requests (token, room_name, guest_name, guest_ip, status)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$token, $roomName, $guestName, $guestIp, 'pending']);
        return (int) $this->db->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPendingForRoom(string $roomName): array
    {
        $this->expireStaleRequests();
        $stmt = $this->db->prepare('
            SELECT id, token, guest_name, guest_ip, requested_at
            FROM meeting_admission_requests
            WHERE room_name = ? AND status = ?
            ORDER BY requested_at ASC
        ');
        $stmt->execute([$roomName, 'pending']);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAdmissionRequest(int $id, string $token): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM meeting_admission_requests WHERE id = ? AND token = ? LIMIT 1');
        $stmt->execute([$id, $token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAdmissionRequestById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM meeting_admission_requests WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function approveAdmission(int $id, string $resolvedBy, string $livekitToken, string $wsUrl): bool
    {
        $stmt = $this->db->prepare('
            UPDATE meeting_admission_requests
            SET status = ?, resolved_at = NOW(), resolved_by = ?, livekit_token = ?, livekit_ws_url = ?
            WHERE id = ? AND status = ?
        ');
        $stmt->execute(['approved', $resolvedBy, $livekitToken, $wsUrl, $id, 'pending']);
        return $stmt->rowCount() > 0;
    }

    public function denyAdmission(int $id, string $resolvedBy): bool
    {
        $stmt = $this->db->prepare('
            UPDATE meeting_admission_requests
            SET status = ?, resolved_at = NOW(), resolved_by = ?
            WHERE id = ? AND status = ?
        ');
        $stmt->execute(['denied', $resolvedBy, $id, 'pending']);
        return $stmt->rowCount() > 0;
    }
}
