<?php

namespace FleetManager\Api\Services;

/**
 * ComposeEnvRenderer — renders the per-host Docker Compose `.env` for a server.
 *
 * Phase D of the native->docker migration. Where the native provisioner ran
 * ~30 apt/systemctl steps and baked secrets into `config.local.php` on the box,
 * the Docker deploy INJECTS everything through one `.env` that `docker compose`
 * reads (see email/docker/.env.example for the canonical key set). The images
 * are immutable; only this file changes per host.
 *
 * This class is deliberately PURE — no Container, no DB, no SSH. It maps a
 * resolved variables array (as produced by TemplateService::generateServerVariables,
 * plus the non-regenerable crypto keys) to a `.env` string, and fails LOUDLY
 * when a value that would brick the deploy is missing. That makes it trivially
 * unit-testable off-box (fleet/api/tests/compose-env-renderer-test.php).
 *
 * The three migration buckets (PLAN.md) are respected here:
 *   - service-name hostnames (DB_HOST=mariadb, REDIS_HOST=redis, ...) are the
 *     INJECT bucket: localhost on the native box becomes the compose service name.
 *   - the non-regenerable secrets (IMAP_ENCRYPTION_KEY, OAUTH_KEYS, JWT PEM paths)
 *     are the MIGRATE bucket: this renderer only references them; it never
 *     invents them (regenerating IMAP_ENCRYPTION_KEY bricks every stored password).
 */
class ComposeEnvRenderer
{
    /** Compose service names on the internal bridge network. */
    private const DB_SERVICE      = 'mariadb';
    private const REDIS_SERVICE   = 'redis';
    private const MEILI_SERVICE   = 'meilisearch';
    private const COLLAB_ADDR     = 'collab:1234';
    private const MAILSYNC_ADDR   = 'mailsync:1235';

    /** JWT PEM pair lives in the shared `jwt_keys` volume (mounted read-only in collab/mailsync). */
    private const JWT_PRIVATE_PATH = '/etc/flowone/jwt/jwt-private.pem';
    private const JWT_PUBLIC_PATH  = '/etc/flowone/jwt/jwt-public.pem';

    /**
     * Keys that ride along in the variables array but are NEVER written to the
     * `.env` (they are seeded into the jwt_keys volume instead). Multi-line PEMs
     * would otherwise trip the "no newline" guard even though they never touch
     * the file.
     */
    private const NOT_EMITTED = ['JWT_PRIVATE_KEY_PEM', 'JWT_PUBLIC_KEY_PEM'];

    /**
     * Keys that MUST be present and non-empty, or the stack cannot boot / would
     * corrupt data. Missing any of these aborts provisioning loudly rather than
     * shipping a broken `.env`.
     */
    private const REQUIRED = [
        'EMAIL_DOMAIN',
        'EMAIL_DB_NAME',
        'EMAIL_DB_USER',
        'EMAIL_DB_PASS',
        'MEILI_MASTER_KEY',
        'IMAP_ENCRYPTION_KEY',
        'DB_ROOT_PASS',
    ];

    /**
     * Render the `.env` file body for a server.
     *
     * @param array $vars    Resolved variables (TemplateService::generateServerVariables + crypto keys).
     * @param array $options Overrides: enable_ssl (bool), registry (string), tag (string),
     *                       app_env (string), imap_host (string).
     * @throws \RuntimeException if a required value is missing or a fatal inconsistency is found.
     */
    public function render(array $vars, array $options = []): string
    {
        $problems = $this->validate($vars, $options);
        if ($problems) {
            throw new \RuntimeException(
                "Cannot render compose .env — " . count($problems) . " problem(s):\n  - "
                . implode("\n  - ", $problems)
            );
        }

        $emailDomain = (string) $vars['EMAIL_DOMAIN'];
        $panelDomain = (string) ($vars['PANEL_DOMAIN'] ?? $emailDomain);
        $mailDomain  = (string) ($vars['MAIL_DOMAIN'] ?? $emailDomain);
        // Mail server FQDN = the mail pod's HELO/myhostname + cert lineage. Fleet's
        // TemplateService sets this to the bare base domain (it owns the A + PTR).
        $serverFqdn  = (string) ($vars['SERVER_FQDN'] ?? $mailDomain);
        $serverIp    = (string) ($vars['SERVER_IP'] ?? '');
        // The web tier and the mail pod share ONE cert lineage = the mail FQDN
        // (its SANs also cover the webmail + panel hosts). The mail pod's
        // TLS_CERT_NAME defaults to SERVER_FQDN, so both serve the very same cert.
        $certName    = $serverFqdn !== '' ? $serverFqdn : $emailDomain;
        // mailsync/IMAP target: prefer the cert-covered FQDN so IMAP_VERIFY_CERT
        // holds under a real cert (falls back to the mail/base domain off-cert).
        $mailHost    = (string) ($options['imap_host'] ?? $serverFqdn);
        $adminEmail  = (string) ($vars['ADMIN_EMAIL'] ?? "admin@{$emailDomain}");
        $enableSsl   = (isset($options['enable_ssl']) ? (bool) $options['enable_ssl'] : true) ? '1' : '0';
        $registry    = (string) ($options['registry'] ?? 'flowone');
        $tag         = (string) ($options['tag'] ?? 'latest');
        $appEnv      = (string) ($options['app_env'] ?? 'prod');

        // AI key: prefer explicit AI_ENCRYPTION_KEY, else the generic ENCRYPTION_KEY.
        $aiKey = (string) ($vars['AI_ENCRYPTION_KEY'] ?? $vars['ENCRYPTION_KEY'] ?? '');

        $scheme = $enableSsl === '1' ? 'https' : 'http';
        $wsScheme = $enableSsl === '1' ? 'wss' : 'ws';

        $kv = [];
        $sec = function (string $title) use (&$kv) { $kv[] = ['#section', $title]; };
        $put = function (string $key, $value) use (&$kv) { $kv[] = [$key, (string) $value]; };

        $sec('Identity / URLs');
        $put('EMAIL_DOMAIN', $emailDomain);
        $put('FRONTEND_URL', "{$scheme}://{$emailDomain}");
        $put('API_URL', "{$scheme}://{$emailDomain}/api");
        $put('APP_ENV', $appEnv);
        $put('APP_DEBUG', 'false');
        $put('ENABLE_SSL', $enableSsl);
        // OLS reads these only when ENABLE_SSL=1. Same lineage as the mail pod's
        // TLS_CERT_NAME so web (443) + mail (imaps/submission) present one cert.
        $put('SSL_CERT_FILE', "/etc/letsencrypt/live/{$certName}/fullchain.pem");
        $put('SSL_KEY_FILE', "/etc/letsencrypt/live/{$certName}/privkey.pem");

        $sec('App database (shared)');
        $put('DB_HOST', self::DB_SERVICE);
        $put('DB_PORT', '3306');
        $put('DB_NAME', $vars['EMAIL_DB_NAME']);
        $put('DB_USER', $vars['EMAIL_DB_USER']);
        $put('DB_PASS', $vars['EMAIL_DB_PASS']);

        $sec('Mail database (Dovecot/Postfix virtual users)');
        $put('MAIL_DB_HOST', self::DB_SERVICE);
        $put('MAIL_DB_NAME', $vars['MAIL_DB_NAME'] ?? 'mailserver');
        $put('MAIL_DB_USER', $vars['MAIL_DB_USER'] ?? 'mailuser');
        $put('MAIL_DB_PASS', $vars['MAIL_DB_PASS'] ?? '');

        // The mail pod runs on the HOST network and reads its identity from these.
        // Heavy AV/spam services default ON for parity; a small box can flip them
        // OFF in vars (ClamAV alone resident-loads ~1.2GB) — mail + DKIM stay up.
        $sec('Mail server pod (host-networked)');
        $put('MAIL_DOMAIN', $mailDomain);
        $put('SERVER_FQDN', $serverFqdn);
        $put('SERVER_IP', $serverIp);
        $put('ADMIN_EMAIL', $adminEmail);
        $put('MAIL_ENABLE_CLAMAV', (string) ($vars['MAIL_ENABLE_CLAMAV'] ?? '1'));
        $put('MAIL_ENABLE_SPAMASSASSIN', (string) ($vars['MAIL_ENABLE_SPAMASSASSIN'] ?? '1'));
        $put('MAIL_ENABLE_RSPAMD', (string) ($vars['MAIL_ENABLE_RSPAMD'] ?? '1'));

        $sec('Redis');
        $put('REDIS_HOST', self::REDIS_SERVICE);
        $put('REDIS_PORT', '6379');
        $put('REDIS_PASSWORD', $vars['REDIS_PASS'] ?? '');
        $put('REDIS_DATABASE', '0');

        $sec('Meilisearch');
        $put('MEILI_HOST', 'http://' . self::MEILI_SERVICE . ':7700');
        $put('MEILI_MASTER_KEY', $vars['MEILI_MASTER_KEY']);
        $put('MEILI_SEARCH_KEY', $vars['MEILI_SEARCH_KEY'] ?? '');

        $sec('Crypto / secrets (MIGRATE byte-for-byte; regenerating bricks data)');
        $put('JWT_ALGORITHM', 'RS256');
        $put('JWT_PRIVATE_KEY_PATH', self::JWT_PRIVATE_PATH);
        $put('JWT_PUBLIC_KEY_PATH', self::JWT_PUBLIC_PATH);
        $put('IMAP_ENCRYPTION_KEY', $vars['IMAP_ENCRYPTION_KEY']);
        $put('AI_ENCRYPTION_KEY', $aiKey);
        $put('OAUTH_KEYS', $vars['OAUTH_KEYS'] ?? '');
        $put('OAUTH_CURRENT_VERSION', $vars['OAUTH_CURRENT_VERSION'] ?? '1');
        $put('SSO_SERVER_KEY', $vars['SSO_SERVER_KEY'] ?? '');

        $sec('Realtime services (compose service names)');
        $put('COLLAB_ADDR', self::COLLAB_ADDR);
        $put('MAILSYNC_ADDR', self::MAILSYNC_ADDR);
        $put('COLLAB_WS_URL', "{$wsScheme}://{$emailDomain}/collab-ws");

        $sec('Calls: coTURN + LiveKit');
        $put('STUN_URL', $vars['STUN_URL'] ?? '');
        $put('TURN_URL', $vars['TURN_URL'] ?? '');
        $put('TURN_SECRET', $vars['TURN_SECRET'] ?? '');
        $put('TURN_TTL', '86400');
        $put('LIVEKIT_API_KEY', $vars['LIVEKIT_API_KEY'] ?? '');
        $put('LIVEKIT_API_SECRET', $vars['LIVEKIT_API_SECRET'] ?? '');
        $put('LIVEKIT_WS_URL', $vars['LIVEKIT_WS_URL'] ?? '');

        $sec('Push (web)');
        $put('VAPID_PUBLIC_KEY', $vars['VAPID_PUBLIC_KEY'] ?? '');
        $put('VAPID_PRIVATE_KEY', $vars['VAPID_PRIVATE_KEY'] ?? '');
        $put('VAPID_SUBJECT', 'mailto:' . $adminEmail);

        $sec('Mail server (mailsync IMAP IDLE target)');
        $put('IMAP_HOST', $mailHost);
        $put('IMAP_PORT', '993');
        $put('IMAP_TLS', 'true');
        $put('IMAP_VERIFY_CERT', $enableSsl === '1' ? 'true' : 'false');

        $sec('Native push toggles (mailsync)');
        $put('FCM_ENABLED', 'false');
        $put('APNS_VOIP_ENABLED', 'false');

        $sec('Panel agent');
        $put('PANEL_API_URL', "https://{$panelDomain}/api");
        // Shared key: Panel external_api <-> Email App panel.api_key.
        $put('PANEL_API_KEY', $vars['EMAIL_API_KEY'] ?? '');

        $sec('Image source');
        $put('REGISTRY', $registry);
        $put('TAG', $tag);

        $sec('OAuth providers (optional)');
        $put('GOOGLE_OAUTH_CLIENT_ID', $vars['GOOGLE_OAUTH_CLIENT_ID'] ?? '');
        $put('GOOGLE_OAUTH_CLIENT_SECRET', $vars['GOOGLE_OAUTH_CLIENT_SECRET'] ?? '');
        $put('MICROSOFT_OAUTH_CLIENT_ID', $vars['MICROSOFT_OAUTH_CLIENT_ID'] ?? '');
        $put('MICROSOFT_OAUTH_CLIENT_SECRET', $vars['MICROSOFT_OAUTH_CLIENT_SECRET'] ?? '');

        $sec('MariaDB container bootstrap');
        $put('MYSQL_ROOT_PASSWORD', $vars['DB_ROOT_PASS']);

        return $this->assemble($kv, $emailDomain);
    }

    /**
     * Return a list of human-readable problems with the inputs. Empty = OK.
     * Kept separate from render() so callers (and tests) can pre-flight.
     */
    public function validate(array $vars, array $options = []): array
    {
        $problems = [];

        foreach (self::REQUIRED as $key) {
            $val = $vars[$key] ?? '';
            if (!is_scalar($val) || trim((string) $val) === '') {
                $problems[] = "missing required value: {$key}";
            }
        }

        // No EMITTED value may contain a newline — it would corrupt the .env line
        // format. PEM material (NOT_EMITTED) is seeded into a volume, not the file.
        foreach ($vars as $k => $v) {
            if (in_array($k, self::NOT_EMITTED, true)) {
                continue;
            }
            if (is_scalar($v) && preg_match('/[\r\n]/', (string) $v)) {
                $problems[] = "value for {$k} contains a newline (would break .env)";
            }
        }

        // LiveKit: an API key with an empty ws_url breaks every call/huddle. This
        // mirrors the loud guard the native installer already enforces.
        if (!empty($vars['LIVEKIT_API_KEY']) && trim((string) ($vars['LIVEKIT_WS_URL'] ?? '')) === '') {
            $problems[] = 'LIVEKIT_API_KEY is set but LIVEKIT_WS_URL is empty '
                . '(stunnel TLS port, e.g. wss://<host>:7443) — empty ws_url breaks all calls/huddles';
        }

        return $problems;
    }

    /**
     * Assemble ordered [key,value] / ['#section',title] entries into the file body.
     */
    private function assemble(array $kv, string $emailDomain): string
    {
        $out = [];
        $out[] = '# FlowOne per-server stack — rendered by Fleet for ' . $emailDomain . '.';
        $out[] = '# Generated ' . gmdate('Y-m-d H:i:s') . ' UTC. Do not edit by hand; re-render from Fleet.';
        $out[] = '# All values are INJECTED at runtime (never baked into the image).';

        foreach ($kv as [$key, $value]) {
            if ($key === '#section') {
                $out[] = '';
                $out[] = '# ---- ' . $value . ' ----';
                continue;
            }
            $out[] = $key . '=' . $value;
        }

        return implode("\n", $out) . "\n";
    }
}
