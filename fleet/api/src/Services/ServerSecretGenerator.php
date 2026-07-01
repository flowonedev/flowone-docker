<?php

namespace FleetManager\Api\Services;

/**
 * ServerSecretGenerator — mints the non-regenerable crypto a Docker-deployed
 * server needs (Phase D of the native->docker migration).
 *
 * On the native path these are generated inside install.sh on the box; the
 * Docker path renders the per-host .env from Fleet, so Fleet must generate them
 * and persist them (TemplateService + servers-table columns) so every
 * re-provision reuses the SAME values. Regenerating any of these bricks data
 * (IMAP_ENCRYPTION_KEY -> stored IMAP passwords) or logs everyone out (JWT pair).
 *
 * Pure factory — no DB, no Container, no side effects beyond openssl RNG/keygen —
 * so it is fully unit-testable (fleet/api/tests/server-secrets-test.php).
 */
class ServerSecretGenerator
{
    /**
     * 32-byte key as 64 hex chars. Used for IMAP_ENCRYPTION_KEY, AI key, SSO key
     * — matches the `openssl rand -hex 32` the native installer uses.
     */
    public static function hexKey(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    /**
     * Generate an RS256 JWT key pair (2048-bit RSA) as PEM strings.
     * The backend signs with the private key; mailsync + collab verify with the
     * public key (mounted from the shared jwt_keys volume).
     *
     * @return array{private:string,public:string}
     * @throws \RuntimeException if openssl keygen/export fails.
     */
    public static function jwtKeyPair(int $bits = 2048): array
    {
        $res = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => $bits,
        ]);
        if ($res === false) {
            throw new \RuntimeException('openssl_pkey_new failed: ' . self::opensslErrors());
        }

        $privatePem = '';
        if (!openssl_pkey_export($res, $privatePem)) {
            throw new \RuntimeException('openssl_pkey_export failed: ' . self::opensslErrors());
        }

        $details = openssl_pkey_get_details($res);
        if ($details === false || empty($details['key'])) {
            throw new \RuntimeException('openssl_pkey_get_details failed: ' . self::opensslErrors());
        }

        return [
            'private' => $privatePem,
            'public'  => $details['key'], // PEM public key
        ];
    }

    /**
     * Fill in any missing Docker crypto on a variables array, leaving already-set
     * (persisted/migrated) values untouched. Returns the augmented array plus the
     * list of keys that were freshly generated (so the caller knows what to persist).
     *
     * @param array $vars variables (as from TemplateService::generateServerVariables)
     * @return array{vars:array,generated:string[]}
     */
    public static function ensureDockerSecrets(array $vars): array
    {
        $generated = [];

        if (empty($vars['IMAP_ENCRYPTION_KEY'])) {
            $vars['IMAP_ENCRYPTION_KEY'] = self::hexKey();
            $generated[] = 'IMAP_ENCRYPTION_KEY';
        }
        if (empty($vars['AI_ENCRYPTION_KEY'])) {
            // Reuse the generic ENCRYPTION_KEY if present, else mint one.
            $vars['AI_ENCRYPTION_KEY'] = !empty($vars['ENCRYPTION_KEY']) ? $vars['ENCRYPTION_KEY'] : self::hexKey();
            $generated[] = 'AI_ENCRYPTION_KEY';
        }
        if (empty($vars['SSO_SERVER_KEY'])) {
            $vars['SSO_SERVER_KEY'] = self::hexKey();
            $generated[] = 'SSO_SERVER_KEY';
        }
        if (empty($vars['JWT_PRIVATE_KEY_PEM']) || empty($vars['JWT_PUBLIC_KEY_PEM'])) {
            $pair = self::jwtKeyPair();
            $vars['JWT_PRIVATE_KEY_PEM'] = $pair['private'];
            $vars['JWT_PUBLIC_KEY_PEM'] = $pair['public'];
            $generated[] = 'JWT_KEY_PAIR';
        }

        return ['vars' => $vars, 'generated' => $generated];
    }

    private static function opensslErrors(): string
    {
        $errs = [];
        while (($e = openssl_error_string()) !== false) {
            $errs[] = $e;
        }
        return $errs ? implode('; ', $errs) : 'unknown';
    }
}
