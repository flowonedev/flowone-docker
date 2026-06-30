<?php

namespace Webmail\Addons\AutomationHub\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\AIAssistant\Services\AIService;

class ConnectionController extends BaseController
{
    private ?\PDO $db = null;

    private function getDb(): \PDO
    {
        if (!$this->db) {
            $dsn = "mysql:host={$this->config['db']['host']};dbname={$this->config['db']['name']};charset=utf8mb4";
            $this->db = new \PDO($dsn, $this->config['db']['user'], $this->config['db']['pass'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
        }
        return $this->db;
    }

    private function getEncryptionKey(): string
    {
        return $this->config['encryption_key'] ?? 'webmail-ai-secret-key-change-me';
    }

    public function list(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $db = $this->getDb();
        $this->ensureTable($db);

        $stmt = $db->prepare("SELECT provider, connected_at, updated_at, meta, 
            CASE WHEN access_token_encrypted IS NOT NULL THEN 1 ELSE 0 END as has_token,
            CASE WHEN api_key_encrypted IS NOT NULL THEN 1 ELSE 0 END as has_api_key
            FROM automation_hub_connections WHERE user_email = ?");
        $stmt->execute([$this->userEmail]);
        $connections = $stmt->fetchAll();

        // Check Google OAuth separately
        $stmt = $db->prepare("SELECT 1 FROM webmail_oauth_tokens WHERE primary_email = ? AND provider = 'google' LIMIT 1");
        $stmt->execute([$this->userEmail]);
        $googleConnected = (bool)$stmt->fetchColumn();

        $result = [];
        foreach ($connections as $c) {
            $result[$c['provider']] = [
                'connected' => true,
                'connected_at' => $c['connected_at'],
                'has_token' => (bool)$c['has_token'],
                'has_api_key' => (bool)$c['has_api_key'],
                'meta' => json_decode($c['meta'] ?? '{}', true),
            ];
        }
        $result['google'] = ['connected' => $googleConnected];

        return Response::success(['connections' => $result]);
    }

    public function save(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $provider = $request->input('provider', '');
        if (!$provider) return Response::badRequest('Provider is required');

        $allowed = ['openweathermap', 'trello', 'mailchimp'];
        if (!in_array($provider, $allowed)) return Response::badRequest('Invalid provider');

        $db = $this->getDb();
        $this->ensureTable($db);
        $secret = $this->getEncryptionKey();

        $apiKey = $request->input('api_key', '');
        $accessToken = $request->input('access_token', '');
        $meta = $request->input('meta') ?: [];
        if (is_string($meta)) {
            $meta = json_decode($meta, true) ?: [];
        }

        $values = [
            'user_email' => $this->userEmail,
            'provider' => $provider,
        ];

        if (!empty($apiKey)) {
            $values['api_key_encrypted'] = AIService::encryptApiKey($apiKey, $secret);
            $meta['key_hint'] = $this->maskKey($apiKey);
        }
        if (!empty($accessToken)) {
            $values['access_token_encrypted'] = AIService::encryptApiKey($accessToken, $secret);
            $meta['token_hint'] = $this->maskKey($accessToken);
        }
        $values['meta'] = json_encode($meta);

        $stmt = $db->prepare("INSERT INTO automation_hub_connections (user_email, provider, api_key_encrypted, access_token_encrypted, meta)
            VALUES (:user_email, :provider, :api_key_encrypted, :access_token_encrypted, :meta)
            ON DUPLICATE KEY UPDATE 
                api_key_encrypted = COALESCE(VALUES(api_key_encrypted), api_key_encrypted),
                access_token_encrypted = COALESCE(VALUES(access_token_encrypted), access_token_encrypted),
                meta = COALESCE(VALUES(meta), meta),
                updated_at = NOW()");

        $stmt->execute([
            'user_email' => $values['user_email'],
            'provider' => $values['provider'],
            'api_key_encrypted' => $values['api_key_encrypted'] ?? null,
            'access_token_encrypted' => $values['access_token_encrypted'] ?? null,
            'meta' => $values['meta'] ?? null,
        ]);

        return Response::success(['saved' => true, 'provider' => $provider]);
    }

    public function disconnect(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $provider = $request->input('provider', '');
        if (!$provider) return Response::badRequest('Provider is required');

        $db = $this->getDb();
        $this->ensureTable($db);

        $stmt = $db->prepare("DELETE FROM automation_hub_connections WHERE user_email = ? AND provider = ?");
        $stmt->execute([$this->userEmail, $provider]);

        return Response::success(['disconnected' => true, 'provider' => $provider]);
    }

    private function maskKey(string $key): string
    {
        $len = strlen($key);
        if ($len <= 6) return str_repeat('*', $len);
        $show = min(4, (int)floor($len / 4));
        return substr($key, 0, $show) . str_repeat('*', $len - $show * 2) . substr($key, -$show);
    }

    private function ensureTable(\PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS automation_hub_connections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_email VARCHAR(255) NOT NULL,
            provider VARCHAR(50) NOT NULL,
            access_token_encrypted TEXT,
            refresh_token_encrypted TEXT,
            token_expires_at DATETIME DEFAULT NULL,
            api_key_encrypted TEXT DEFAULT NULL,
            meta JSON DEFAULT NULL,
            connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_user_provider (user_email, provider)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
