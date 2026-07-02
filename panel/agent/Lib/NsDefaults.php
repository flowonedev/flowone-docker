<?php
/**
 * Nameserver defaults resolver.
 *
 * Single source of truth for which NS1/NS2 hostnames this box advertises in
 * the zones it creates. The operator-set config file always wins; when it is
 * absent the defaults are derived from THIS server's own base domain
 * (ns1.<base> / ns2.<base>) — never from an operator/vendor domain, so a
 * freshly provisioned client box can't leak someone else's nameservers into
 * its DNS zones. The Fleet provisioner writes the config file at deploy time
 * (per-server override), so this fallback only matters for old installs.
 */

namespace VpsAdmin\Agent\Lib;

final class NsDefaults
{
    public const CONFIG_FILE = '/var/www/vps-admin/.dns_ns_config.json';

    /**
     * Effective NS configuration: {enabled: bool, ns1: string, ns2: string}.
     * File contents (when present and valid) are merged over the derived
     * defaults, so a partial file still yields a complete config.
     */
    public static function load(): array
    {
        $defaults = self::derived();

        if (is_readable(self::CONFIG_FILE)) {
            $decoded = json_decode((string) file_get_contents(self::CONFIG_FILE), true);
            if (is_array($decoded)) {
                return array_merge($defaults, $decoded);
            }
        }

        return $defaults;
    }

    /**
     * Defaults derived from this box's own identity. When no base domain can
     * be determined we return enabled=false with empty hostnames — zone
     * creation then simply skips NS records instead of publishing a wrong one.
     */
    public static function derived(): array
    {
        $base = self::baseDomain();
        if ($base === '') {
            return ['enabled' => false, 'ns1' => '', 'ns2' => ''];
        }
        return ['enabled' => true, 'ns1' => 'ns1.' . $base, 'ns2' => 'ns2.' . $base];
    }

    /**
     * The server's base domain (e.g. "devcon3.hu"): host part of the panel's
     * app.url with the well-known service prefix stripped, falling back to
     * the machine FQDN. Empty string when neither is usable.
     */
    public static function baseDomain(): string
    {
        $host = '';

        $configFile = '/var/www/vps-admin/api/config.php';
        $localConfigFile = '/var/www/vps-admin/api/config.local.php';
        if (is_readable($configFile)) {
            $config = (array) require $configFile;
            if (is_readable($localConfigFile)) {
                $config = array_replace_recursive($config, (array) require $localConfigFile);
            }
            $url = (string) ($config['app']['url'] ?? '');
            $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        }

        if ($host === '') {
            $host = (string) (gethostname() ?: '');
        }

        // Only a real FQDN is useful as a zone base.
        if (strpos($host, '.') === false) {
            return '';
        }

        return preg_replace('/^(panel|vps|mail|email|www)\./', '', strtolower($host)) ?? '';
    }
}
