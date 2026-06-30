<?php

namespace FleetManager\Api\Controllers;

use FleetManager\Api\Core\Request;
use FleetManager\Api\Core\Response;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * Fleet-wide settings (key/value `settings` table).
 *
 * Exposes the SSH MANAGEMENT KEY the Fleet Manager uses to connect to hardened
 * (pxr@1985) boxes. Storing it here (encrypted, rotatable from the dashboard)
 * means operators never hardcode a private key in config.local.php and can swap
 * it instantly if it is ever compromised. The private key is NEVER returned by
 * the API - only a status + fingerprint.
 */
class SettingsController extends BaseController
{
    private const KEY_PRIV = 'ssh_management_key';
    private const KEY_PASS = 'ssh_management_key_passphrase';
    // Non-secret fleet-wide SSH login defaults (one place for all servers).
    private const KEY_LOCAL_KEY_PATH = 'ssh_default_local_key_path';
    private const KEY_FLEET_IGNORE_IPS = 'ssh_fleet_ignore_ips';

    /**
     * GET /api/settings/ssh
     * Status only - never returns the private key itself.
     */
    public function getSshKey(Request $request): Response
    {
        $db = $this->getDatabase();
        $enc = $this->getEncryption();

        $configured = false;
        $source = 'none';            // database | config | none
        $hasPassphrase = false;
        $fingerprint = null;

        $priv = $this->readSetting($db, self::KEY_PRIV);
        if ($priv !== null && $priv !== '') {
            try {
                $plain = $enc->decrypt($priv);
                if ($plain !== '') {
                    $source = 'database';
                    $configured = true;
                    $passRaw = $this->readSetting($db, self::KEY_PASS);
                    $pass = ($passRaw !== null && $passRaw !== '') ? $enc->decrypt($passRaw) : '';
                    $hasPassphrase = $pass !== '';
                    $fingerprint = $this->fingerprint($plain, $pass);
                }
            } catch (\Exception $e) {
                // stored but undecryptable - report not configured
            }
        }

        // Fall back to a config-file key if the DB has none.
        if (!$configured) {
            $cfgPath = trim((string)($this->container->getConfig('ssh.management_key_path') ?: ''));
            if ($cfgPath !== '' && @file_exists($cfgPath)) {
                $source = 'config';
                $configured = true;
                $cfgPass = (string)($this->container->getConfig('ssh.management_key_passphrase') ?: '');
                $hasPassphrase = $cfgPass !== '';
                try { $fingerprint = $this->fingerprint((string)@file_get_contents($cfgPath), $cfgPass); }
                catch (\Throwable $e) { /* non-fatal */ }
            }
        }

        return Response::success([
            'configured' => $configured,
            'source' => $source,
            'has_passphrase' => $hasPassphrase,
            'fingerprint' => $fingerprint,
            // The public key authorized on pxr (for reference - this is what the
            // private key above must match).
            'authorized_public_key' => trim((string)($this->container->getConfig('ssh.pxr_authorized_key') ?: '')),
            // Fleet-wide SSH login defaults (non-secret). Used as the default local
            // key path in the copy-paste SSH command, and the fail2ban ignoreip
            // whitelist baked into every deploy. Per-server values still override.
            'default_local_key_path' => (string)($this->readSetting($db, self::KEY_LOCAL_KEY_PATH) ?? ''),
            'fleet_ignore_ips' => (string)($this->readSetting($db, self::KEY_FLEET_IGNORE_IPS)
                ?? (string)($this->container->getConfig('ssh.fleet_ignore_ips') ?: '')),
        ]);
    }

    /**
     * PUT /api/settings/ssh-defaults   { default_local_key_path, fleet_ignore_ips }
     *
     * Fleet-wide, NON-secret SSH login defaults set in one place for every server:
     *  - default_local_key_path: the operator's local private-key path dropped into
     *    the copy-paste "ssh -i ..." command (per-server override still wins).
     *  - fleet_ignore_ips: IP(s)/CIDR(s) the panel connects FROM, added to fail2ban
     *    ignoreip on every deploy so the panel can never ban itself off a box.
     */
    public function updateSshDefaults(Request $request): Response
    {
        if ($resp = $this->requireSuperAdmin()) return $resp;

        $db = $this->getDatabase();

        $localKeyPath = trim((string)$request->input('default_local_key_path', ''));

        // Validate ignore IPs: space/comma separated IPs or CIDRs.
        $clean = [];
        foreach (preg_split('/[\s,]+/', (string)$request->input('fleet_ignore_ips', ''), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            $addr = explode('/', $tok)[0];
            if (!filter_var($addr, FILTER_VALIDATE_IP)) {
                return Response::error("Not a valid IP or CIDR: {$tok}", 422);
            }
            $clean[] = $tok;
        }
        $ignoreIps = implode(' ', array_values(array_unique($clean)));

        $this->writeSetting($db, self::KEY_LOCAL_KEY_PATH, $localKeyPath);
        $this->writeSetting($db, self::KEY_FLEET_IGNORE_IPS, $ignoreIps);

        $this->logAction('settings.ssh_defaults.update', null, null, 'success', []);

        return Response::success([
            'default_local_key_path' => $localKeyPath,
            'fleet_ignore_ips' => $ignoreIps,
            'message' => 'SSH login defaults saved.',
        ]);
    }

    /**
     * PUT /api/settings/ssh   { private_key, passphrase }
     * An empty private_key clears the stored key (reverts to the config fallback).
     */
    public function updateSshKey(Request $request): Response
    {
        if ($resp = $this->requireSuperAdmin()) return $resp;

        $db = $this->getDatabase();
        $enc = $this->getEncryption();

        $priv = (string)$request->input('private_key', '');
        $pass = (string)$request->input('passphrase', '');

        if (trim($priv) === '') {
            $this->writeSetting($db, self::KEY_PRIV, '');
            $this->writeSetting($db, self::KEY_PASS, '');
            $this->invalidateCache();
            return Response::success(['configured' => false, 'message' => 'Management key cleared']);
        }

        // Validate it actually loads with the supplied passphrase before storing.
        try {
            PublicKeyLoader::load($priv, $pass === '' ? false : $pass);
        } catch (\Throwable $e) {
            return Response::error(
                'That private key could not be loaded - check the key contents and passphrase.',
                422
            );
        }

        $this->writeSetting($db, self::KEY_PRIV, $enc->encrypt($priv));
        $this->writeSetting($db, self::KEY_PASS, $pass === '' ? '' : $enc->encrypt($pass));
        $this->invalidateCache();

        $this->logAction('settings.ssh_management_key.update', null, null, 'success', [
            'has_passphrase' => $pass !== '',
        ]);

        return Response::success([
            'configured' => true,
            'has_passphrase' => $pass !== '',
            'fingerprint' => $this->fingerprint($priv, $pass),
            'message' => 'Management key saved. The Fleet Manager will use it to reach hardened (pxr) boxes.',
        ]);
    }

    private function readSetting(\PDO $db, string $key): ?string
    {
        try {
            $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
            $st->execute([$key]);
            $v = $st->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function writeSetting(\PDO $db, string $key, string $value): void
    {
        $st = $db->prepare(
            "INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
        );
        $st->execute([$key, $value]);
    }

    /**
     * Remove the on-disk cache copy so the next connection re-materialises the
     * (now changed/cleared) key from the database.
     */
    private function invalidateCache(): void
    {
        $dir = rtrim((string)($this->container->getConfig('ssh.key_path') ?: '/var/www/vps-fleet/var/keys/'), '/') . '/';
        @unlink($dir . '_fleet_mgmt.key');
    }

    /** OpenSSH-style SHA256 fingerprint of the key's public half, or null. */
    private function fingerprint(string $privateKey, string $passphrase): ?string
    {
        try {
            $key = PublicKeyLoader::load($privateKey, $passphrase === '' ? false : $passphrase);
            $pub = $key->getPublicKey()->toString('OpenSSH');
            $parts = explode(' ', trim($pub));
            $blob = base64_decode($parts[1] ?? '', true);
            if ($blob === false || $blob === '') return null;
            return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
