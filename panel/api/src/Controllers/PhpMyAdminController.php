<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * phpMyAdmin Access Controller
 * 
 * Generates secure time-limited tokens for phpMyAdmin access.
 * Tokens are HMAC-signed and expire after 5 minutes.
 */
class PhpMyAdminController extends BaseController
{
    private const TOKEN_EXPIRY = 300; // 5 minutes
    
    /**
     * Generate a phpMyAdmin access token
     * 
     * POST /api/phpmyadmin/token
     * Body: { "database": "db_name" }
     */
    public function generateToken(Request $request): Response
    {
        $database = $request->input('database');
        
        if (empty($database)) {
            return Response::error('Database name is required', 400);
        }
        
        // Get current user
        $user = $this->getCurrentUser();
        if (!$user) {
            return Response::error('Unauthorized', 401);
        }
        
        // Check if user can access this database
        // Super admins can access all, regular users only their linked databases
        if ($user->role !== 'super_admin') {
            if (!$this->canAccessDatabase($user->sub, $database)) {
                return Response::error('Access denied to this database', 403);
            }
        }
        
        // Generate token
        $tokenData = $this->createToken($user->sub, $database);
        
        // Log the access
        $this->logAction('phpmyadmin.token', $database, 'success', [
            'user_id' => $user->sub,
            'expires_at' => date('Y-m-d H:i:s', $tokenData['expires'])
        ]);
        
        return Response::success([
            'url' => $tokenData['url'],
            'expires_in' => self::TOKEN_EXPIRY,
        ], 'Token generated');
    }
    
    /**
     * Create a signed token
     */
    private function createToken(int $userId, string $database): array
    {
        $expires = time() + self::TOKEN_EXPIRY;
        
        // Token payload
        $payload = [
            'uid' => $userId,
            'db' => $database,
            'exp' => $expires,
            'nonce' => bin2hex(random_bytes(8)), // Prevent replay
        ];
        
        // Encode payload
        $payloadJson = json_encode($payload);
        $payloadBase64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
        
        // Sign with HMAC-SHA256
        $secret = $this->getSigningSecret();
        $signature = hash_hmac('sha256', $payloadBase64, $secret);
        
        // Final token: payload.signature
        $token = $payloadBase64 . '.' . $signature;
        
        // Build URL
        $panelUrl = $this->container->getConfig('app.panel_url') ?? 'https://panel.devcon1.hu';
        $url = $panelUrl . '/phpmyadmin/gate.php?token=' . urlencode($token);
        
        return [
            'token' => $token,
            'url' => $url,
            'expires' => $expires,
        ];
    }
    
    /**
     * Get the signing secret (uses JWT secret)
     */
    private function getSigningSecret(): string
    {
        $secret = $this->container->getConfig('jwt.secret');
        if (empty($secret)) {
            throw new \RuntimeException('JWT secret not configured');
        }
        return $secret . '_pma'; // Add suffix for phpMyAdmin tokens
    }
    
    /**
     * Check if user can access a database
     */
    private function canAccessDatabase(int $userId, string $database): bool
    {
        $db = $this->container->getDatabase();
        
        // Get user's assigned sites
        $stmt = $db->prepare("SELECT domain FROM user_sites WHERE user_id = ?");
        $stmt->execute([$userId]);
        $userSites = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        if (empty($userSites)) {
            return false;
        }
        
        // Check if database is linked to any of user's sites
        $placeholders = str_repeat('?,', count($userSites) - 1) . '?';
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM database_links 
            WHERE db_name = ? AND domain IN ($placeholders)
        ");
        $stmt->execute(array_merge([$database], $userSites));
        
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Validate a token (static method for gate.php)
     */
    public static function validateToken(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        
        [$payloadBase64, $signature] = $parts;
        
        // Verify signature
        $expectedSignature = hash_hmac('sha256', $payloadBase64, $secret . '_pma');
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }
        
        // Decode payload
        $payloadJson = base64_decode(strtr($payloadBase64, '-_', '+/'));
        $payload = json_decode($payloadJson, true);
        
        if (!$payload) {
            return null;
        }
        
        // Check expiry
        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }
        
        return $payload;
    }
}

