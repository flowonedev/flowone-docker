<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Ols;

/**
 * Renders the per-site vhost.conf file fed to OpenLiteSpeed via the
 * `configFile` directive in the main httpd_config.conf vhost block.
 *
 * The template here mirrors the legacy hand-built vhost.conf format
 * the panel has shipped to production for over a year:
 *   - docRoot anchored to $VH_ROOT/public_html (variable expansion done
 *     by OLS, not us)
 *   - vhAliases for `www.<domain>` and `mail.<domain>` (matches legacy
 *     VhostAction::generateVhostConfig output)
 *   - per-vhost error + access logs in $VH_ROOT/logs
 *   - per-vhost LSAPI subprocess running as <siteUser>:<siteGroup>
 *   - module cache enabled with per-vhost storage
 *   - rewrite + .htaccess auto-load
 *   - root context (`context /`) with security-header rewrite rules
 *     (HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy)
 *     so OLS knows how to serve the root URL out of the box
 *   - .well-known/acme-challenge context for Certbot HTTP-01 validation
 *   - errorPage entries for 404 / 403 / 500 / 503 pointing at
 *     /error/<code>.html. The actual HTML files in
 *     <docroot>/error/ are NOT staged by this saga yet; until they
 *     are, OLS falls back to its built-in error pages, which is
 *     functionally fine. (Follow-up: add an error-page seeding
 *     helper to HomeDirCreateStep so the rendered URL resolves.)
 *
 * Things deliberately NOT in this template:
 *   - SSL block (`vhssl`): added by SslIssueStep once Certbot has
 *     materialised the cert files. We do NOT pre-write a vhssl block
 *     pointing at a non-existent cert: OLS refuses to start in that
 *     state.
 *   - phpIniOverride content: empty by default; the panel UI's
 *     "PHP settings" tab adds entries via a separate update flow.
 *
 * Variables we render:
 *   - site_user      primary system user (also the LSAPI runner)
 *   - site_group     primary system group (defaults to site_user)
 *   - php_lsapi      PHP version dir ("lsphp83", "lsphp82", ...)
 *   - admin_email    admin contact email for log/error notifications
 *   - memory_soft_limit / memory_hard_limit  per-LSAPI memory caps
 */
final class VhostConfigTemplate
{
    public const DEFAULT_PHP_LSAPI = 'lsphp83';

    /**
     * @param array{
     *   site_user: string,
     *   site_group?: string,
     *   php_lsapi?: string,
     *   admin_email?: string,
     *   memory_soft_limit?: string,
     *   memory_hard_limit?: string
     * } $vars
     */
    public function render(array $vars): string
    {
        $siteUser = $this->requireStr($vars, 'site_user');
        $siteGroup = (string) ($vars['site_group'] ?? $siteUser);
        $phpLsapi = (string) ($vars['php_lsapi'] ?? self::DEFAULT_PHP_LSAPI);
        if (!preg_match('/^lsphp[0-9]+$/', $phpLsapi)) {
            throw new \InvalidArgumentException("Invalid php_lsapi: '{$phpLsapi}'");
        }
        $adminEmail = (string) ($vars['admin_email'] ?? 'admin@localhost');
        $memSoft = (string) ($vars['memory_soft_limit'] ?? '1024M');
        $memHard = (string) ($vars['memory_hard_limit'] ?? '1024M');

        // Use heredoc with single-quote-style $-escaping for OLS variables
        // we WANT to survive into the rendered file. PHP variable
        // interpolation only fires on $siteUser etc. - the literal
        // "\$VH_ROOT" stays as-is for OLS to interpret at server-start.
        return <<<CONFIG
docRoot                   \$VH_ROOT/public_html
vhDomain                  \$VH_NAME
vhAliases                 www.\$VH_NAME, mail.\$VH_NAME
adminEmails               {$adminEmail}
enableGzip                0
enableIpGeo               1

index  {
  useServer               0
  indexFiles              index.php, index.html
}

errorlog \$VH_ROOT/logs/\$VH_NAME.error_log {
  useServer               0
  logLevel                WARN
  rollingSize             10M
}

accesslog \$VH_ROOT/logs/\$VH_NAME.access_log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b \"%{Referer}i\" \"%{User-Agent}i\""
  logHeaders              5
  rollingSize             10M
  keepDays                10
  compressArchive         1
}

scripthandler  {
  add                     lsapi:{$siteUser} php
}

extprocessor {$siteUser} {
  type                    lsapi
  address                 UDS://tmp/lshttpd/{$siteUser}.sock
  maxConns                10
  env                     LSAPI_CHILDREN=10
  initTimeout             600
  retryTimeout            0
  persistConn             1
  pcKeepAliveTimeout      1
  respBuffer              0
  autoStart               1
  path                    /usr/local/lsws/{$phpLsapi}/bin/lsphp
  extUser                 {$siteUser}
  extGroup                {$siteGroup}
  memSoftLimit            {$memSoft}
  memHardLimit            {$memHard}
  procSoftLimit           400
  procHardLimit           500
}

phpIniOverride  {

}

module cache {
  storagePath /usr/local/lsws/cachedata/\$VH_NAME
}

rewrite  {
  enable                  1
  autoLoadHtaccess        1
}

context /.well-known/acme-challenge {
  location                \$DOC_ROOT/.well-known/acme-challenge
  allowBrowse             1

  rewrite  {
    enable                0
  }
  addDefaultCharset       off

  phpIniOverride  {

  }
}

context / {
  location                \$DOC_ROOT/
  allowBrowse             1
  rewrite  {
    enable                1
    rules                 <<<END_RULES
RewriteRule .* - [E=XFO:SAMEORIGIN]
RewriteRule .* - [E=XCTO:nosniff]
RewriteRule .* - [E=RP:strict-origin-when-cross-origin]
END_RULES
  }
  extraHeaders            <<<END_HEADERS
Strict-Transport-Security: max-age=31536000; includeSubDomains; preload
X-Frame-Options: %{XFO}e
X-Content-Type-Options: %{XCTO}e
Referrer-Policy: %{RP}e
END_HEADERS
}

errorPage 404 {
  url /error/404.html
}
errorPage 403 {
  url /error/403.html
}
errorPage 500 {
  url /error/500.html
}
errorPage 503 {
  url /error/503.html
}

CONFIG;
    }

    /**
     * Append a `vhssl` block to an existing rendered vhost.conf so OLS
     * starts terminating HTTPS for this domain once Let's Encrypt has
     * issued a cert.
     *
     * Why this is a separate method from render():
     *   - render() emits the HTTP-only baseline that OLS can serve as
     *     soon as the saga's first OLS restart fires. Pre-writing a
     *     `vhssl` block pointing at /etc/letsencrypt/live/<domain>
     *     before certbot has materialised those files makes OLS
     *     refuse to load the vhost (cert paths must resolve at parse
     *     time).
     *   - appendVhssl() runs in SslIssueStep AFTER `certbot certonly`
     *     has succeeded and the live symlinks exist on disk.
     *
     * Idempotency: if the input already contains a `vhssl` block, the
     * call returns the input unchanged. Re-runs of the saga (resume,
     * reprovision) therefore can't double-append.
     *
     * Output shape mirrors the legacy `addSslToVhostConfig()` golden
     * output (TLS 1.2+ ciphers, OCSP stapling, QUIC enabled, session
     * tickets on, BREACH mitigation via gzip-off — gzip is already
     * `0` in render() above so we don't need to mutate it).
     *
     * Cert path uses `\$VH_NAME` so OLS expands it at parse time. That
     * way moving a cert dir or renaming the domain (rare but possible
     * via the rename saga in Step 4d) just requires a vhost reload —
     * no template edit.
     */
    public function appendVhssl(string $existingConfig): string
    {
        if (preg_match('/vhssl\s*\{/i', $existingConfig)) {
            // Already SSL-enabled. Re-running the SSL flip would
            // accumulate duplicate blocks, which OLS warns about and
            // then ignores all but the first - silent footgun.
            return $existingConfig;
        }

        $sslBlock = <<<'SSL'

vhssl  {
  keyFile                 /etc/letsencrypt/live/$VH_NAME/privkey.pem
  certFile                /etc/letsencrypt/live/$VH_NAME/fullchain.pem
  certChain               1
  sslProtocol             24
  enableECDHE             1
  renegProtection         1
  sslSessionCache         1
  enableSpdy              15
  enableStapling          1
  ocspRespMaxAge          86400
  ciphers                 ECDHE-RSA-AES128-GCM-SHA256:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES256-GCM-SHA384
  sslSessionTickets       1
  enableQuic              1
}
SSL;

        // Normalise trailing whitespace on the input so we never end
        // up with three blank lines between the last directive and
        // our appended block. Operators reading the file should see
        // exactly one blank-line separator.
        return rtrim($existingConfig, "\r\n") . "\n" . $sslBlock . "\n";
    }

    private function requireStr(array $vars, string $key): string
    {
        if (!isset($vars[$key]) || !is_string($vars[$key]) || $vars[$key] === '') {
            throw new \InvalidArgumentException("VhostConfigTemplate missing required '{$key}'");
        }
        return $vars[$key];
    }
}
