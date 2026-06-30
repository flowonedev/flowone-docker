<?php

namespace Webmail\Addons\AutomationHub\Controllers;

use Webmail\Controllers\BaseController;
use Webmail\Core\Request;
use Webmail\Core\Response;
use Webmail\Addons\AIAssistant\Services\AIService;

class TrelloConnectionController extends BaseController
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

    /**
     * Returns the Trello authorize URL for the frontend to redirect to.
     */
    public function getAuthUrl(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $apiKey = $this->config['trello']['api_key'] ?? '';
        if (!$apiKey) return Response::badRequest('Trello API key not configured on server');

        $callbackUrl = ($this->config['app_url'] ?? '') . '/api/automation-hub/trello/callback';
        $authUrl = 'https://trello.com/1/authorize?' . http_build_query([
            'expiration' => 'never',
            'name' => 'Automation Hub',
            'scope' => 'read,write',
            'response_type' => 'token',
            'key' => $apiKey,
            'return_url' => $callbackUrl,
        ]);

        return Response::success(['auth_url' => $authUrl]);
    }

    /**
     * Save a Trello token that was obtained via the client-side redirect.
     * Trello's authorize flow returns the token directly in the URL fragment,
     * so the frontend reads it and POSTs it here.
     */
    public function saveToken(Request $request): Response
    {
        $auth = $this->requireAuth($request);
        if ($auth) return $auth;

        $data = $request->getBody();
        $token = $data['token'] ?? '';
        if (!$token) return Response::badRequest('Token is required');

        $db = $this->getDb();
        $secret = $this->getEncryptionKey();
        $encrypted = AIService::encryptApiKey($token, $secret);

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

        $stmt = $db->prepare("INSERT INTO automation_hub_connections (user_email, provider, access_token_encrypted)
            VALUES (?, 'trello', ?)
            ON DUPLICATE KEY UPDATE access_token_encrypted = VALUES(access_token_encrypted), updated_at = NOW()");
        $stmt->execute([$this->userEmail, $encrypted]);

        return Response::success(['connected' => true]);
    }
}
