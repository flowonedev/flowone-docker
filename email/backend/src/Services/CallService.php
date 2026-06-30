<?php

namespace Webmail\Services;

use Firebase\JWT\JWT;

/**
 * CallService - Voice/Video Call Support
 * 
 * Features:
 * - LiveKit SFU room token generation
 * - TURN credential generation (legacy, time-limited, HMAC-based)
 * - Call history storage and retrieval
 * - Call status tracking
 */
class CallService
{
    private \PDO $db;
    private array $config;
    
    public function __construct(array $config)
    {
        $this->config = $config;
        
        $this->db = \Webmail\Core\Database::getConnection($config);
        
        $this->ensureTablesExist();
    }
    
    /**
     * Ensure call_history table exists
     */
    private function ensureTablesExist(): void
    {
        try {
            $result = $this->db->query("SHOW TABLES LIKE 'call_history'");
            if ($result->rowCount() === 0) {
                $migrationFile = __DIR__ . '/../../migrations/045_call_history.sql';
                if (file_exists($migrationFile)) {
                    $sql = file_get_contents($migrationFile);
                    $statements = array_filter(array_map('trim', explode(';', $sql)));
                    foreach ($statements as $statement) {
                        if (!empty($statement) && !str_starts_with($statement, '--')) {
                            $this->db->exec($statement);
                        }
                    }
                    error_log("CallService: Created call_history table from migration");
                }
            }
        } catch (\PDOException $e) {
            error_log("CallService: Migration check failed: " . $e->getMessage());
        }
    }
    
    /**
     * Generate time-limited TURN credentials using HMAC
     * 
     * Uses Coturn's long-term credential mechanism with --use-auth-secret.
     * Username format: timestamp:email
     * Password: HMAC-SHA1(timestamp:email, shared_secret)
     */
    public function getTurnCredentials(string $userEmail): array
    {
        $webrtcConfig = $this->config['webrtc'] ?? [];
        $secret = $webrtcConfig['turn_secret'] ?? '';
        $ttl = $webrtcConfig['turn_ttl'] ?? 86400;
        
        $stunUrl = $webrtcConfig['stun_url'] ?? 'stun:stun.l.google.com:19302';
        $turnUrl = $webrtcConfig['turn_url'] ?? '';
        
        $iceServers = [];
        
        // STUN server (no auth needed)
        if ($stunUrl) {
            $iceServers[] = ['urls' => $stunUrl];
        }
        
        // TURN server (generate time-limited credentials)
        if ($turnUrl && $secret) {
            $timestamp = time() + $ttl;
            $username = $timestamp . ':' . $userEmail;
            $credential = base64_encode(hash_hmac('sha1', $username, $secret, true));
            
            $iceServers[] = [
                'urls' => $turnUrl,
                'username' => $username,
                'credential' => $credential
            ];
            
            // Also add TURNS (TLS) variant if available
            $turnsUrl = str_replace('turn:', 'turns:', $turnUrl);
            $turnsUrl = str_replace(':3478', ':5349', $turnsUrl);
            $iceServers[] = [
                'urls' => $turnsUrl,
                'username' => $username,
                'credential' => $credential
            ];
        }
        
        return [
            'success' => true,
            'iceServers' => $iceServers,
            'ttl' => $ttl
        ];
    }
    
    /**
     * Generate a LiveKit access token for a participant to join a room.
     * 
     * LiveKit tokens are JWTs signed with the API secret. The token encodes:
     * - The participant's identity (email)
     * - The room they can join
     * - Their permissions (publish, subscribe, data channels)
     *
     * @param string $roomName   The LiveKit room name (typically the callId)
     * @param string $userEmail  Participant email (used as unique identity)
     * @param string $userName   Display name shown to other participants
     * @param bool   $canPublish Whether participant can publish audio/video tracks
     * @param array  $extraGrants Optional: canSubscribe, canPublishData, hidden (inside video grant), metadata (top-level string)
     * @return array { token: string, ws_url: string }
     */
    public function getLiveKitToken(string $roomName, string $userEmail, string $userName = '', bool $canPublish = true, array $extraGrants = []): array
    {
        $livekitConfig = $this->config['livekit'] ?? [];
        $apiKey = $livekitConfig['api_key'] ?? '';
        $apiSecret = $livekitConfig['api_secret'] ?? '';
        $wsUrl = $livekitConfig['ws_url'] ?? '';
        
        if (!$apiKey || !$apiSecret) {
            throw new \RuntimeException('LiveKit API credentials not configured');
        }
        
        $now = time();

        $canSubscribe = array_key_exists('canSubscribe', $extraGrants)
            ? (bool) $extraGrants['canSubscribe'] : true;
        $canPublishData = array_key_exists('canPublishData', $extraGrants)
            ? (bool) $extraGrants['canPublishData'] : true;
        $hidden = array_key_exists('hidden', $extraGrants)
            ? (bool) $extraGrants['hidden'] : false;
        $roomAdmin = array_key_exists('roomAdmin', $extraGrants)
            ? (bool) $extraGrants['roomAdmin'] : false;

        // LiveKit token spec: https://docs.livekit.io/realtime/concepts/authentication/
        $video = [
            'roomJoin' => true,
            'room' => $roomName,
            'canPublish' => $canPublish,
            'canSubscribe' => $canSubscribe,
            'canPublishData' => $canPublishData,
            'hidden' => $hidden,
        ];
        if ($roomAdmin) {
            $video['roomAdmin'] = true;
        }
        $payload = [
            'iss' => $apiKey,
            'sub' => $userEmail,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + 86400,
            'name' => $userName ?: explode('@', $userEmail)[0],
            'video' => $video,
        ];

        if (!empty($extraGrants['metadata']) && is_string($extraGrants['metadata'])) {
            $payload['metadata'] = $extraGrants['metadata'];
        }
        
        $token = JWT::encode($payload, $apiSecret, 'HS256');
        
        return [
            'token' => $token,
            'ws_url' => $wsUrl,
        ];
    }
    
    /**
     * Save a completed/missed/rejected call to history
     */
    public function saveCallHistory(array $data): array
    {
        try {
            $stmt = $this->db->prepare('
                INSERT INTO call_history 
                    (call_id, conversation_id, initiated_by, call_type, status, 
                     started_at, answered_at, ended_at, duration_seconds, participants, had_screen_share)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    answered_at = VALUES(answered_at),
                    ended_at = VALUES(ended_at),
                    duration_seconds = VALUES(duration_seconds),
                    had_screen_share = VALUES(had_screen_share)
            ');
            
            $stmt->execute([
                $data['call_id'],
                $data['conversation_id'],
                $data['initiated_by'],
                $data['call_type'] ?? 'voice',
                $data['status'] ?? 'completed',
                $this->toMysqlDatetime($data['started_at'] ?? null) ?? date('Y-m-d H:i:s'),
                $this->toMysqlDatetime($data['answered_at'] ?? null),
                $this->toMysqlDatetime($data['ended_at'] ?? null) ?? date('Y-m-d H:i:s'),
                $data['duration_seconds'] ?? 0,
                json_encode($data['participants'] ?? []),
                $data['had_screen_share'] ?? 0
            ]);
            
            return ['success' => true, 'id' => (int)$this->db->lastInsertId()];
        } catch (\PDOException $e) {
            error_log("CallService: Failed to save call history: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to save call history'];
        }
    }
    
    /**
     * Convert an ISO 8601 datetime string (e.g. 2026-02-12T14:09:10.809Z) to MySQL format (Y-m-d H:i:s).
     * Returns null if input is null or empty.
     */
    private function toMysqlDatetime(?string $value): ?string
    {
        if ($value === null || $value === '') return null;
        try {
            return (new \DateTime($value))->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            // If it's already in MySQL format or something else, return as-is
            return $value;
        }
    }
    
    /**
     * Get call history for a conversation
     */
    public function getCallHistory(int $conversationId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->db->prepare('
            SELECT ch.*, oc.email as initiator_email, oc.display_name as initiator_name
            FROM call_history ch
            JOIN organization_colleagues oc ON ch.initiated_by = oc.id
            WHERE ch.conversation_id = ?
            ORDER BY ch.started_at DESC
            LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset . '
        ');
        $stmt->execute([$conversationId]);
        $calls = $stmt->fetchAll();
        
        foreach ($calls as &$call) {
            $call['participants'] = $call['participants'] ? json_decode($call['participants'], true) : [];
        }
        
        return $calls;
    }
    
    /**
     * Get colleague ID by email
     */
    public function getColleagueIdByEmail(string $email): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM organization_colleagues WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ? (int)$row['id'] : null;
    }
}

