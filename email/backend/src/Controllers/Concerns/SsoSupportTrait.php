<?php

namespace Webmail\Controllers\Concerns;

use Webmail\Core\Request;
use Webmail\Core\Response;

/**
 * Small, stateless helpers shared by the SSO controllers
 * (SSOController + DeviceAuthController): TLS enforcement, token/code/uuid
 * generation, and request/label sanitisation. Kept in a trait so neither
 * controller has to duplicate them and both stay focused on their flow.
 *
 * The using class must expose `$this->config` (BaseController already does).
 */
trait SsoSupportTrait
{
    /** Reject plaintext HTTP on public endpoints (allowed in dev). */
    protected function requireTLS(Request $request): ?Response
    {
        if (getenv('APP_ENV') === 'development' || getenv('APP_DEBUG') === 'true') {
            return null;
        }

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
            || ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on'
            || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;

        if (!$isSecure) {
            return Response::json(['error' => 'HTTPS required'], 403);
        }
        return null;
    }

    protected function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    protected function generateSecureToken(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    protected function generateCode(int $length): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }

    protected function isUuid(string $value): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value);
    }

    /** Trim a device label to something safe + short for storage and display. */
    protected function sanitizeDeviceLabel(string $label): string
    {
        $label = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $label);
        $label = trim(preg_replace('/\s+/', ' ', $label));
        if ($label === '') {
            $label = 'Desktop app';
        }
        return mb_substr($label, 0, 200);
    }

    /** This server's public origin (no trailing slash), for the QR verify URL. */
    protected function selfOrigin(): string
    {
        $origin = (string)($this->config['app']['frontend_url'] ?? '');
        if ($origin === '') {
            $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
            $host = preg_replace('/:\d+$/', '', $host);
            $origin = $host !== '' ? 'https://' . $host : '';
        }
        return rtrim($origin, '/');
    }
}
