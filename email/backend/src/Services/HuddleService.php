<?php

namespace Webmail\Services;

use PDO;

class HuddleService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->ensureTables();
    }

    private function ensureTables(): void
    {
        try {
            $this->db->query('SELECT 1 FROM chat_huddles LIMIT 1');
        } catch (\PDOException $e) {
            // Tables don't exist, create them
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS chat_huddles (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    conversation_id INT UNSIGNED NOT NULL,
                    started_by INT UNSIGNED NOT NULL,
                    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    ended_at DATETIME DEFAULT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    INDEX idx_huddle_conv_active (conversation_id, is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $this->db->exec("
                CREATE TABLE IF NOT EXISTS chat_huddle_participants (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    huddle_id INT UNSIGNED NOT NULL,
                    colleague_id INT UNSIGNED NOT NULL,
                    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    left_at DATETIME DEFAULT NULL,
                    is_muted TINYINT(1) NOT NULL DEFAULT 0,
                    is_deafened TINYINT(1) NOT NULL DEFAULT 0,
                    UNIQUE KEY idx_huddle_participant (huddle_id, colleague_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        }
    }

    private function getColleague(string $email): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM organization_colleagues WHERE email = ?');
        $stmt->execute([strtolower($email)]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Start a new huddle or return existing active one
     */
    public function startHuddle(int $conversationId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Check if user is participant of conversation
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$conversationId, $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        // Check for existing active huddle
        $stmt = $this->db->prepare('SELECT * FROM chat_huddles WHERE conversation_id = ? AND is_active = 1');
        $stmt->execute([$conversationId]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Join existing huddle
            return $this->joinHuddle($existing['id'], $colleague['id']);
        }

        // Create new huddle
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('
                INSERT INTO chat_huddles (conversation_id, started_by, started_at, is_active)
                VALUES (?, ?, NOW(), 1)
            ');
            $stmt->execute([$conversationId, $colleague['id']]);
            $huddleId = (int)$this->db->lastInsertId();

            // Add creator as first participant
            $stmt = $this->db->prepare('
                INSERT INTO chat_huddle_participants (huddle_id, colleague_id, joined_at)
                VALUES (?, ?, NOW())
            ');
            $stmt->execute([$huddleId, $colleague['id']]);

            $this->db->commit();

            return [
                'success' => true,
                'huddle' => $this->getHuddleInfo($huddleId)
            ];
        } catch (\PDOException $e) {
            $this->db->rollBack();
            error_log("HuddleService::startHuddle error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to start huddle'];
        }
    }

    /**
     * Join an existing huddle
     */
    private function joinHuddle(int $huddleId, int $colleagueId): array
    {
        // Check if already in huddle
        $stmt = $this->db->prepare('
            SELECT * FROM chat_huddle_participants 
            WHERE huddle_id = ? AND colleague_id = ? AND left_at IS NULL
        ');
        $stmt->execute([$huddleId, $colleagueId]);
        if ($stmt->fetch()) {
            return ['success' => true, 'huddle' => $this->getHuddleInfo($huddleId)];
        }

        // Re-join (if they left before) or insert new
        $stmt = $this->db->prepare('
            INSERT INTO chat_huddle_participants (huddle_id, colleague_id, joined_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE joined_at = NOW(), left_at = NULL, is_muted = 0, is_deafened = 0
        ');
        $stmt->execute([$huddleId, $colleagueId]);

        return ['success' => true, 'huddle' => $this->getHuddleInfo($huddleId)];
    }

    /**
     * Join a huddle by ID (public method)
     */
    public function joinHuddleById(int $huddleId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Check huddle exists and is active
        $stmt = $this->db->prepare('SELECT * FROM chat_huddles WHERE id = ? AND is_active = 1');
        $stmt->execute([$huddleId]);
        $huddle = $stmt->fetch();
        if (!$huddle) {
            return ['success' => false, 'error' => 'Huddle not found or ended'];
        }

        // Check access to conversation
        $stmt = $this->db->prepare('SELECT 1 FROM chat_participants WHERE conversation_id = ? AND colleague_id = ?');
        $stmt->execute([$huddle['conversation_id'], $colleague['id']]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'error' => 'Access denied'];
        }

        return $this->joinHuddle($huddleId, $colleague['id']);
    }

    /**
     * Leave a huddle
     */
    public function leaveHuddle(int $huddleId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $stmt = $this->db->prepare('
            UPDATE chat_huddle_participants 
            SET left_at = NOW() 
            WHERE huddle_id = ? AND colleague_id = ? AND left_at IS NULL
        ');
        $stmt->execute([$huddleId, $colleague['id']]);

        // Check if any participants remain
        $stmt = $this->db->prepare('
            SELECT COUNT(*) FROM chat_huddle_participants 
            WHERE huddle_id = ? AND left_at IS NULL
        ');
        $stmt->execute([$huddleId]);
        $remaining = (int)$stmt->fetchColumn();

        if ($remaining === 0) {
            // End the huddle
            $stmt = $this->db->prepare('
                UPDATE chat_huddles SET is_active = 0, ended_at = NOW() WHERE id = ?
            ');
            $stmt->execute([$huddleId]);
        }

        return ['success' => true, 'ended' => $remaining === 0];
    }

    /**
     * Get active huddle for a conversation
     */
    public function getActiveHuddle(int $conversationId, string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        $stmt = $this->db->prepare('SELECT * FROM chat_huddles WHERE conversation_id = ? AND is_active = 1');
        $stmt->execute([$conversationId]);
        $huddle = $stmt->fetch();

        if (!$huddle) {
            return ['success' => true, 'huddle' => null];
        }

        return ['success' => true, 'huddle' => $this->getHuddleInfo((int)$huddle['id'])];
    }

    /**
     * Get all active huddles for conversations the user belongs to
     */
    public function getAllActiveHuddles(string $userEmail): array
    {
        $colleague = $this->getColleague($userEmail);
        if (!$colleague) {
            return ['success' => false, 'error' => 'User not found'];
        }

        // Find all active huddles in conversations this user participates in
        $stmt = $this->db->prepare('
            SELECT h.* FROM chat_huddles h
            INNER JOIN chat_participants cp ON cp.conversation_id = h.conversation_id
            WHERE h.is_active = 1 AND cp.colleague_id = ?
        ');
        $stmt->execute([$colleague['id']]);
        $huddles = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($huddles as $huddle) {
            $info = $this->getHuddleInfo((int)$huddle['id']);
            if (!empty($info)) {
                $result[] = $info;
            }
        }

        return ['success' => true, 'huddles' => $result];
    }

    /**
     * Get full huddle info with participants
     */
    private function getHuddleInfo(int $huddleId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM chat_huddles WHERE id = ?');
        $stmt->execute([$huddleId]);
        $huddle = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$huddle) return [];

        $stmt = $this->db->prepare('
            SELECT hp.*, oc.email, oc.display_name, oc.avatar_path
            FROM chat_huddle_participants hp
            JOIN organization_colleagues oc ON hp.colleague_id = oc.id
            WHERE hp.huddle_id = ? AND hp.left_at IS NULL
        ');
        $stmt->execute([$huddleId]);
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $huddle['participants'] = $participants;
        return $huddle;
    }
}

