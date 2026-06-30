<?php

namespace Webmail\Services;

use Firebase\JWT\JWT;

/**
 * LiveKit server API (Twirp JSON) for admin operations.
 */
class LiveKitAdminService
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    private function httpsBase(): string
    {
        $ws = $this->config['livekit']['ws_url'] ?? '';
        if ($ws === '') {
            throw new \RuntimeException('LiveKit ws_url not configured');
        }
        return preg_replace('#^wss?://#i', 'https://', $ws) ?? '';
    }

    private function adminJwt(?string $roomName = null, int $ttlSeconds = 120): string
    {
        $livekit = $this->config['livekit'] ?? [];
        $apiKey = $livekit['api_key'] ?? '';
        $apiSecret = $livekit['api_secret'] ?? '';
        if (!$apiKey || !$apiSecret) {
            throw new \RuntimeException('LiveKit API credentials not configured');
        }
        $now = time();
        $video = [
            'roomAdmin' => true,
            'roomList' => true,
        ];
        if ($roomName !== null && $roomName !== '') {
            $video['room'] = $roomName;
        }
        $payload = [
            'iss' => $apiKey,
            'sub' => 'flowone-admin',
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttlSeconds,
            'video' => $video,
        ];

        return JWT::encode($payload, $apiSecret, 'HS256');
    }

    /**
     * @param array<string, mixed> $body
     * @return array{ok: bool, status: int, body: string}
     */
    private function twirpPost(string $method, array $body, ?string $roomForJwt = null): array
    {
        $url = rtrim($this->httpsBase(), '/') . '/twirp/livekit.RoomService/' . $method;
        $jwt = $this->adminJwt($roomForJwt);
        $json = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return ['ok' => false, 'status' => 0, 'body' => 'json_encode failed'];
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0, 'body' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $jwt,
            ],
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            return ['ok' => false, 'status' => $status, 'body' => $err ?: 'curl_exec failed'];
        }
        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => (string) $resp];
    }

    public function removeParticipant(string $room, string $identity): bool
    {
        $r = $this->twirpPost('RemoveParticipant', [
            'room' => $room,
            'identity' => $identity,
        ], $room);
        return $r['ok'];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listParticipants(string $room): array
    {
        $r = $this->twirpPost('ListParticipants', ['room' => $room], $room);
        if (!$r['ok']) {
            return [];
        }
        $decoded = json_decode($r['body'], true);
        if (!is_array($decoded)) {
            return [];
        }
        $participants = $decoded['participants'] ?? [];
        return is_array($participants) ? $participants : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRooms(): array
    {
        $r = $this->twirpPost('ListRooms', [], null);
        if (!$r['ok']) {
            return [];
        }
        $decoded = json_decode($r['body'], true);
        if (!is_array($decoded)) {
            return [];
        }
        $rooms = $decoded['rooms'] ?? [];
        return is_array($rooms) ? $rooms : [];
    }

    /**
     * @param string[]|null $destinationIdentities
     */
    public function sendData(string $room, string $payload, ?array $destinationIdentities = null, string $kind = 'RELIABLE'): bool
    {
        $body = [
            'room' => $room,
            'data' => base64_encode($payload),
            'kind' => $kind,
        ];
        if ($destinationIdentities !== null && $destinationIdentities !== []) {
            $body['destination_identities'] = array_values($destinationIdentities);
        }
        $r = $this->twirpPost('SendData', $body, $room);
        return $r['ok'];
    }
}
