<?php

namespace FleetManager\Api\Services;

use FleetManager\Api\Core\Container;

/**
 * Config Extractor Service
 * Extracts server configurations via SSH for blueprint creation
 */
class ConfigExtractorService
{
    private Container $container;
    private ?SSHService $ssh = null;
    private array $log = [];
    private bool $dryRun = false;
    private bool $localMode = false;
    private ?array $categoriesToExtract = null; // If set, only extract these categories

    // Extraction categories with their paths and patterns
    // COMPREHENSIVE extraction - captures ALL config files for full server replication
    private const EXTRACTION_MAP = [
        // ============================================================
        // WEB SERVER - OpenLiteSpeed
        // ============================================================
        'openlitespeed' => [
            'name' => 'OpenLiteSpeed Web Server',
            'check' => '/usr/local/lsws/bin/lshttpd',
            'files' => [
                ['path' => '/usr/local/lsws/conf/httpd_config.conf', 'required' => true],
                ['path' => '/usr/local/lsws/conf/mime.properties', 'required' => false],
                ['path' => '/usr/local/lsws/admin/conf/admin_config.conf', 'required' => false],
            ],
            // NOTE: We intentionally DON'T extract /usr/local/lsws/conf/vhosts
            // Vhosts are created from templates during provisioning (panel, email, fleet)
            // Client site vhosts are created dynamically via the panel
            'directories' => [
                '/usr/local/lsws/conf/templates',
            ],
            'glob_patterns' => [
                '/usr/local/lsws/conf/*.conf',
                '/usr/local/lsws/conf/*.properties',
            ],
            'commands' => [
                ['cmd' => '/usr/local/lsws/bin/lshttpd -v 2>&1 | head -1', 'filename' => 'ols-version.txt'],
            ],
        ],
        'modsecurity' => [
            'name' => 'ModSecurity WAF',
            'check' => '/usr/local/lsws/conf/modsec.conf',
            'files' => [
                ['path' => '/usr/local/lsws/conf/modsec.conf', 'required' => false],
            ],
            'directories' => [
                '/usr/local/lsws/conf/modsec',
                '/etc/modsecurity',
            ],
            'glob_patterns' => [
                '/usr/local/lsws/conf/modsec/*.conf',
                '/etc/modsecurity/*.conf',
                '/etc/modsecurity/rules/*.conf',
            ],
        ],
        
        // ============================================================
        // PHP CONFIGURATION
        // ============================================================
        'php' => [
            'name' => 'PHP Configuration',
            'check' => '/usr/local/lsws/lsphp83/bin/php',
            'files' => [
                ['path' => '/usr/local/lsws/lsphp83/etc/php/8.3/litespeed/php.ini', 'required' => false],
                ['path' => '/usr/local/lsws/lsphp83/etc/php/8.3/cli/php.ini', 'required' => false],
                ['path' => '/etc/php/8.3/fpm/php.ini', 'required' => false],
                ['path' => '/etc/php/8.3/cli/php.ini', 'required' => false],
            ],
            'directories' => [
                '/usr/local/lsws/lsphp83/etc/php/8.3/litespeed/conf.d',
                '/usr/local/lsws/lsphp83/etc/php/8.3/mods-available',
                '/etc/php/8.3/fpm/pool.d',
                '/etc/php/8.3/mods-available',
            ],
            'glob_patterns' => [
                '/etc/php/*/fpm/pool.d/*.conf',
                '/etc/php/*/cli/conf.d/*.ini',
            ],
            'commands' => [
                ['cmd' => '/usr/local/lsws/lsphp83/bin/php -v 2>&1 | head -1', 'filename' => 'php-version.txt'],
                ['cmd' => '/usr/local/lsws/lsphp83/bin/php -m 2>&1', 'filename' => 'php-modules.txt'],
            ],
        ],
        
        // ============================================================
        // DATABASE - MariaDB/MySQL
        // ============================================================
        'mariadb' => [
            'name' => 'MariaDB Database Server',
            'check' => '/usr/bin/mariadb',
            'files' => [
                ['path' => '/etc/mysql/my.cnf', 'required' => false],
                ['path' => '/etc/mysql/mariadb.cnf', 'required' => false],
                ['path' => '/etc/my.cnf', 'required' => false],
            ],
            'directories' => [
                '/etc/mysql/conf.d',
                '/etc/mysql/mariadb.conf.d',
            ],
            'glob_patterns' => [
                '/etc/mysql/*.cnf',
                '/etc/mysql/conf.d/*.cnf',
                '/etc/mysql/mariadb.conf.d/*.cnf',
            ],
            'commands' => [
                ['cmd' => 'mariadb --version 2>&1 | head -1', 'filename' => 'mariadb-version.txt'],
                ['cmd' => 'mariadb -u root -e "SHOW DATABASES;" 2>/dev/null || echo "Access denied"', 'filename' => 'databases-list.txt'],
            ],
        ],
        
        // ============================================================
        // MAIL SERVER - Postfix MTA
        // ============================================================
        'postfix' => [
            'name' => 'Postfix Mail Transfer Agent',
            'check' => '/usr/sbin/postfix',
            'files' => [
                ['path' => '/etc/postfix/main.cf', 'required' => true],
                ['path' => '/etc/postfix/master.cf', 'required' => true],
                ['path' => '/etc/postfix/dynamicmaps.cf', 'required' => false],
                ['path' => '/etc/postfix/header_checks', 'required' => false],
                ['path' => '/etc/postfix/body_checks', 'required' => false],
                ['path' => '/etc/postfix/sender_access', 'required' => false],
                ['path' => '/etc/postfix/client_access', 'required' => false],
                ['path' => '/etc/postfix/helo_access', 'required' => false],
                ['path' => '/etc/postfix/recipient_access', 'required' => false],
                ['path' => '/etc/postfix/transport', 'required' => false],
                ['path' => '/etc/postfix/relay_domains', 'required' => false],
                ['path' => '/etc/postfix/sasl_passwd', 'required' => false],
                ['path' => '/etc/aliases', 'required' => false],
                ['path' => '/etc/mailname', 'required' => false],
            ],
            'glob_patterns' => [
                '/etc/postfix/*.cf',
                '/etc/postfix/mysql-*.cf',
                '/etc/postfix/virtual*',
                '/etc/postfix/sasl/*',
                '/etc/postfix/ssl/*',
            ],
            'commands' => [
                ['cmd' => 'postconf -n 2>/dev/null', 'filename' => 'postconf-non-default.txt'],
                ['cmd' => 'postfix --version 2>&1 | head -1', 'filename' => 'postfix-version.txt'],
            ],
        ],
        
        // ============================================================
        // MAIL SERVER - Dovecot IMAP/POP3
        // ============================================================
        'dovecot' => [
            'name' => 'Dovecot IMAP/POP3 Server',
            'check' => '/usr/sbin/dovecot',
            'files' => [
                ['path' => '/etc/dovecot/dovecot.conf', 'required' => true],
                ['path' => '/etc/dovecot/dovecot-sql.conf.ext', 'required' => false],
                ['path' => '/etc/dovecot/dovecot-ldap.conf.ext', 'required' => false],
                ['path' => '/etc/dovecot/dovecot-dict-sql.conf.ext', 'required' => false],
            ],
            'directories' => [
                '/etc/dovecot/conf.d',
                '/etc/dovecot/sieve',
                '/etc/dovecot/sieve.d',
            ],
            'glob_patterns' => [
                '/etc/dovecot/*.conf',
                '/etc/dovecot/*.conf.ext',
                '/etc/dovecot/conf.d/*.conf',
                '/etc/dovecot/sieve/*.sieve',
            ],
            'commands' => [
                ['cmd' => 'dovecot --version 2>&1', 'filename' => 'dovecot-version.txt'],
                ['cmd' => 'doveconf -n 2>/dev/null', 'filename' => 'doveconf-non-default.txt'],
            ],
        ],
        
        // ============================================================
        // MAIL - DKIM/DMARC/SPF
        // ============================================================
        'opendkim' => [
            'name' => 'OpenDKIM Email Signing',
            'check' => '/usr/sbin/opendkim',
            // IMPORTANT: We deliberately DO NOT clone DKIM private keys, the public
            // .txt records, the KeyTable, the SigningTable or TrustedHosts. Those are
            // per-domain / secret material that belongs ONLY to the source server's
            // own sites. ProvisioningService::installOpenDKIM() regenerates a fresh
            // keypair + KeyTable/SigningTable/TrustedHosts for THIS box's own domain
            // during deploy. Cloning the source's copies would (a) copy every source
            // site's PRIVATE key onto every new box (a security hole) and (b) get
            // re-deployed in the later deploy_configs step, overwriting the new box's
            // correct signing tables with the source's domains - which breaks DKIM
            // signing for the box's real domain. Only generic, non-domain config is
            // cloned here.
            'files' => [
                ['path' => '/etc/opendkim.conf', 'required' => false],
                ['path' => '/etc/default/opendkim', 'required' => false],
            ],
        ],
        'opendmarc' => [
            'name' => 'OpenDMARC',
            'check' => '/usr/sbin/opendmarc',
            'files' => [
                ['path' => '/etc/opendmarc.conf', 'required' => false],
                ['path' => '/etc/default/opendmarc', 'required' => false],
            ],
            'glob_patterns' => [
                '/etc/opendmarc/*.conf',
            ],
        ],
        
        // ============================================================
        // MAIL - Spam Filtering
        // ============================================================
        'spamassassin' => [
            'name' => 'SpamAssassin',
            'check' => '/usr/bin/spamassassin',
            'files' => [
                ['path' => '/etc/spamassassin/local.cf', 'required' => false],
                ['path' => '/etc/default/spamassassin', 'required' => false],
            ],
            'directories' => [
                '/etc/spamassassin',
                '/etc/mail/spamassassin',
            ],
            'glob_patterns' => [
                '/etc/spamassassin/*.cf',
            ],
        ],
        'rspamd' => [
            'name' => 'Rspamd Spam Filter',
            'check' => '/usr/bin/rspamd',
            'directories' => [
                '/etc/rspamd',
                '/etc/rspamd/local.d',
                '/etc/rspamd/override.d',
            ],
            'glob_patterns' => [
                '/etc/rspamd/*.conf',
                '/etc/rspamd/local.d/*.conf',
                '/etc/rspamd/override.d/*.conf',
            ],
        ],
        'amavis' => [
            'name' => 'Amavis Mail Filter',
            'check' => '/usr/sbin/amavisd-new',
            'files' => [
                ['path' => '/etc/amavis/conf.d/50-user', 'required' => false],
            ],
            'directories' => [
                '/etc/amavis/conf.d',
            ],
            'glob_patterns' => [
                '/etc/amavis/conf.d/*',
            ],
        ],
        
        // ============================================================
        // MAIL - Antivirus
        // ============================================================
        'clamav' => [
            'name' => 'ClamAV Antivirus',
            'check' => '/usr/bin/clamscan',
            'files' => [
                ['path' => '/etc/clamav/clamd.conf', 'required' => false],
                ['path' => '/etc/clamav/freshclam.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/clamav',
            ],
        ],
        
        // ============================================================
        // SECURITY - Fail2ban
        // ============================================================
        'fail2ban' => [
            'name' => 'Fail2ban Intrusion Prevention',
            'check' => '/usr/bin/fail2ban-client',
            'files' => [
                ['path' => '/etc/fail2ban/fail2ban.conf', 'required' => false],
                ['path' => '/etc/fail2ban/fail2ban.local', 'required' => false],
                ['path' => '/etc/fail2ban/jail.conf', 'required' => false],
                ['path' => '/etc/fail2ban/jail.local', 'required' => false],
            ],
            'directories' => [
                '/etc/fail2ban/jail.d',
                '/etc/fail2ban/filter.d',
                '/etc/fail2ban/action.d',
            ],
            'glob_patterns' => [
                '/etc/fail2ban/*.conf',
                '/etc/fail2ban/*.local',
                '/etc/fail2ban/jail.d/*.conf',
                '/etc/fail2ban/jail.d/*.local',
                '/etc/fail2ban/filter.d/*.conf',
                '/etc/fail2ban/filter.d/*.local',
                '/etc/fail2ban/action.d/*.conf',
                '/etc/fail2ban/action.d/*.local',
            ],
            'commands' => [
                ['cmd' => 'fail2ban-client status 2>/dev/null', 'filename' => 'fail2ban-status.txt'],
                ['cmd' => 'fail2ban-client version 2>&1', 'filename' => 'fail2ban-version.txt'],
            ],
        ],
        
        // ============================================================
        // SECURITY - CPGuard
        // ============================================================
        // Real install layout (matches the panel agent's CpguardAction and the
        // live server): configs in /etc/cpguard, app + IP lists in /opt/cpguard.
        // The old /usr/local/cpguard check NEVER matched, so blueprints silently
        // skipped CPGuard. Detection now uses the license file - present on any
        // activated install.
        //
        // Intentionally NOT extracted:
        //   /etc/cpguard/LICENSE_cPGuard  - license keys are IP-bound, per server
        //   /opt/cpguard/app/**           - runtime SQLite DBs (reports, incidents)
        //   /opt/cpguard/logs/**          - logs
        'cpguard' => [
            'name' => 'CPGuard Security',
            'check' => '/etc/cpguard/LICENSE_cPGuard',
            'files' => [
                ['path' => '/etc/cpguard/conf/main.conf', 'required' => false],
                ['path' => '/etc/cpguard/cpguard_modsec100.conf', 'required' => false],
            ],
            'glob_patterns' => [
                // Tuning + WAF wiring: badbots.txt, bfurls.txt, wafurls.txt,
                // rules.txt, whitelistfiles.txt, blacklistfiles.txt, whitelist.conf
                '/etc/cpguard/*.txt',
                '/etc/cpguard/*.conf',
                '/etc/cpguard/conf/*.conf',
                // IP / domain white- and blacklists kept under /opt/cpguard
                '/opt/cpguard/*.txt',
            ],
            'commands' => [
                ['cmd' => 'systemctl is-active cpguard 2>&1; ls /opt/cpguard/app 2>/dev/null | head -5', 'filename' => 'cpguard-state.txt'],
            ],
        ],
        
        // ============================================================
        // SECURITY - Firewall
        // ============================================================
        'firewalld' => [
            'name' => 'FirewallD',
            'check' => '/usr/bin/firewall-cmd',
            'files' => [
                ['path' => '/etc/firewalld/firewalld.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/firewalld/zones',
                '/etc/firewalld/services',
                '/etc/firewalld/icmptypes',
                '/etc/firewalld/ipsets',
                '/etc/firewalld/helpers',
                '/etc/firewalld/policies',
            ],
            'glob_patterns' => [
                '/etc/firewalld/*.conf',
                '/etc/firewalld/zones/*.xml',
                '/etc/firewalld/services/*.xml',
            ],
            'commands' => [
                ['cmd' => 'firewall-cmd --list-all 2>/dev/null', 'filename' => 'firewall-rules.txt'],
                ['cmd' => 'firewall-cmd --list-all-zones 2>/dev/null', 'filename' => 'firewall-zones.txt'],
            ],
        ],
        'iptables' => [
            'name' => 'IPTables Rules',
            'files' => [
                ['path' => '/etc/iptables/rules.v4', 'required' => false],
                ['path' => '/etc/iptables/rules.v6', 'required' => false],
                ['path' => '/etc/iptables.rules', 'required' => false],
            ],
            'commands' => [
                ['cmd' => 'iptables -L -n -v 2>/dev/null', 'filename' => 'iptables-current.txt'],
                ['cmd' => 'ip6tables -L -n -v 2>/dev/null', 'filename' => 'ip6tables-current.txt'],
            ],
        ],
        
        // ============================================================
        // VPN - OpenVPN
        // ============================================================
        'openvpn' => [
            'name' => 'OpenVPN Server',
            'check' => '/usr/sbin/openvpn',
            'files' => [
                ['path' => '/etc/openvpn/server.conf', 'required' => false],
                ['path' => '/etc/default/openvpn', 'required' => false],
            ],
            'directories' => [
                '/etc/openvpn',
                '/etc/openvpn/server',
                '/etc/openvpn/client',
            ],
            'glob_patterns' => [
                '/etc/openvpn/*.conf',
                '/etc/openvpn/server/*.conf',
                '/etc/openvpn/easy-rsa/pki/vars',
            ],
        ],
        'wireguard' => [
            'name' => 'WireGuard VPN',
            'check' => '/usr/bin/wg',
            'directories' => [
                '/etc/wireguard',
            ],
            'glob_patterns' => [
                '/etc/wireguard/*.conf',
            ],
        ],
        
        // ============================================================
        // CACHING - Redis & LSMCD
        // ============================================================
        'redis' => [
            'name' => 'Redis Cache Server',
            'check' => '/usr/bin/redis-server',
            'files' => [
                ['path' => '/etc/redis/redis.conf', 'required' => false],
                ['path' => '/etc/redis.conf', 'required' => false],
                ['path' => '/etc/default/redis-server', 'required' => false],
            ],
            'directories' => [
                '/etc/redis',
            ],
            'glob_patterns' => [
                '/etc/redis/*.conf',
            ],
            'commands' => [
                ['cmd' => 'redis-cli INFO server 2>/dev/null | head -20', 'filename' => 'redis-info.txt'],
            ],
        ],
        'lsmcd' => [
            'name' => 'LiteSpeed Memcached (LSMCD)',
            'check' => '/usr/local/lsmcd/bin/lsmcd',
            'files' => [
                ['path' => '/usr/local/lsmcd/conf/node.conf', 'required' => false],
                ['path' => '/usr/local/lsmcd/conf/lsmcd.conf', 'required' => false],
            ],
            'directories' => [
                '/usr/local/lsmcd/conf',
            ],
            'glob_patterns' => [
                '/usr/local/lsmcd/conf/*.conf',
            ],
        ],
        
        // ============================================================
        // SSH & AUTHENTICATION
        // ============================================================
        'ssh' => [
            'name' => 'SSH Server',
            'check' => '/usr/sbin/sshd',
            'files' => [
                ['path' => '/etc/ssh/sshd_config', 'required' => true],
                ['path' => '/etc/ssh/ssh_config', 'required' => false],
                ['path' => '/etc/ssh/ssh_known_hosts', 'required' => false],
            ],
            'directories' => [
                '/etc/ssh/sshd_config.d',
            ],
            'glob_patterns' => [
                '/etc/ssh/sshd_config.d/*.conf',
            ],
        ],
        'pam' => [
            'name' => 'PAM Authentication',
            'files' => [
                ['path' => '/etc/pam.d/common-auth', 'required' => false],
                ['path' => '/etc/pam.d/common-password', 'required' => false],
                ['path' => '/etc/pam.d/common-session', 'required' => false],
                ['path' => '/etc/pam.d/sshd', 'required' => false],
                ['path' => '/etc/pam.d/sudo', 'required' => false],
            ],
            'glob_patterns' => [
                '/etc/pam.d/*',
            ],
        ],
        'sudo' => [
            'name' => 'Sudo Configuration',
            'files' => [
                ['path' => '/etc/sudoers', 'required' => false],
            ],
            'directories' => [
                '/etc/sudoers.d',
            ],
            'glob_patterns' => [
                '/etc/sudoers.d/*',
            ],
        ],
        
        // ============================================================
        // SYSTEM - Kernel & Tuning
        // ============================================================
        'sysctl' => [
            'name' => 'Kernel Parameters (sysctl)',
            'files' => [
                ['path' => '/etc/sysctl.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/sysctl.d',
            ],
            'glob_patterns' => [
                '/etc/sysctl.d/*.conf',
            ],
            'commands' => [
                ['cmd' => 'sysctl -a 2>/dev/null | head -200', 'filename' => 'sysctl-current.txt'],
            ],
        ],
        'limits' => [
            'name' => 'System Limits',
            'files' => [
                ['path' => '/etc/security/limits.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/security/limits.d',
            ],
            'glob_patterns' => [
                '/etc/security/limits.d/*.conf',
            ],
        ],
        
        // ============================================================
        // SYSTEM - Systemd Services
        // ============================================================
        'systemd' => [
            'name' => 'Custom Systemd Services',
            'glob_patterns' => [
                '/etc/systemd/system/*.service',
                '/etc/systemd/system/*.timer',
                '/etc/systemd/system/*.socket',
                '/etc/systemd/system/multi-user.target.wants/*.service',
            ],
            'commands' => [
                ['cmd' => 'systemctl list-unit-files --state=enabled 2>/dev/null', 'filename' => 'systemd-enabled.txt'],
            ],
        ],
        
        // ============================================================
        // SYSTEM - Cron & Scheduled Tasks
        // ============================================================
        'cron' => [
            'name' => 'Scheduled Tasks (Cron)',
            'files' => [
                ['path' => '/etc/crontab', 'required' => false],
                ['path' => '/etc/anacrontab', 'required' => false],
            ],
            'directories' => [
                '/etc/cron.d',
                '/etc/cron.daily',
                '/etc/cron.hourly',
                '/etc/cron.weekly',
                '/etc/cron.monthly',
            ],
            'glob_patterns' => [
                '/etc/cron.d/*',
                '/etc/cron.daily/*',
                '/etc/cron.hourly/*',
                '/etc/cron.weekly/*',
                '/etc/cron.monthly/*',
                '/var/spool/cron/crontabs/*',
            ],
            'commands' => [
                ['cmd' => 'crontab -l 2>/dev/null || echo "No crontab"', 'filename' => 'root-crontab.txt'],
                ['cmd' => 'for user in $(cut -f1 -d: /etc/passwd); do echo "=== $user ===" && crontab -u $user -l 2>/dev/null; done', 'filename' => 'all-crontabs.txt'],
            ],
        ],
        
        // ============================================================
        // SYSTEM - Logrotate
        // ============================================================
        'logrotate' => [
            'name' => 'Log Rotation',
            'files' => [
                ['path' => '/etc/logrotate.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/logrotate.d',
            ],
            'glob_patterns' => [
                '/etc/logrotate.d/*',
            ],
        ],
        
        // ============================================================
        // SSL/TLS - Let's Encrypt
        // ============================================================
        'letsencrypt' => [
            'name' => 'Let\'s Encrypt SSL',
            'check' => '/usr/bin/certbot',
            'files' => [
                ['path' => '/etc/letsencrypt/cli.ini', 'required' => false],
            ],
            'directories' => [
                '/etc/letsencrypt/renewal',
                '/etc/letsencrypt/renewal-hooks/pre',
                '/etc/letsencrypt/renewal-hooks/post',
                '/etc/letsencrypt/renewal-hooks/deploy',
            ],
            'glob_patterns' => [
                '/etc/letsencrypt/renewal/*.conf',
                '/etc/letsencrypt/renewal-hooks/deploy/*',
            ],
            'commands' => [
                ['cmd' => 'certbot certificates 2>/dev/null', 'filename' => 'certbot-certificates.txt'],
                ['cmd' => 'ls -la /etc/letsencrypt/live/ 2>/dev/null', 'filename' => 'ssl-certs-list.txt'],
            ],
        ],
        
        // ============================================================
        // NETWORK
        // ============================================================
        'network' => [
            'name' => 'Network Configuration',
            'files' => [
                ['path' => '/etc/hosts', 'required' => false],
                ['path' => '/etc/hostname', 'required' => false],
                ['path' => '/etc/resolv.conf', 'required' => false],
                ['path' => '/etc/network/interfaces', 'required' => false],
                ['path' => '/etc/netplan/01-netcfg.yaml', 'required' => false],
            ],
            'directories' => [
                '/etc/netplan',
                '/etc/network/interfaces.d',
            ],
            'glob_patterns' => [
                '/etc/netplan/*.yaml',
                '/etc/network/interfaces.d/*',
            ],
            'commands' => [
                ['cmd' => 'ip addr show', 'filename' => 'ip-addresses.txt'],
                ['cmd' => 'ip route show', 'filename' => 'ip-routes.txt'],
                ['cmd' => 'ss -tlnp', 'filename' => 'listening-ports.txt'],
            ],
        ],
        'dns' => [
            'name' => 'DNS Configuration',
            'files' => [
                ['path' => '/etc/bind/named.conf', 'required' => false],
                ['path' => '/etc/bind/named.conf.local', 'required' => false],
                ['path' => '/etc/bind/named.conf.options', 'required' => false],
            ],
            'directories' => [
                '/etc/bind/zones',
            ],
            'glob_patterns' => [
                '/etc/bind/*.conf',
                '/etc/bind/zones/*',
            ],
        ],
        
        // ============================================================
        // APPLICATIONS - DEVCON Ecosystem
        // ============================================================
        'panel' => [
            'name' => 'DEVCON VPS Panel',
            'check' => '/var/www/vps-admin/api/public/index.php',
            'files' => [
                ['path' => '/var/www/vps-admin/api/config.local.php', 'required' => false],
                ['path' => '/var/www/vps-admin/api/config.php', 'required' => false],
                ['path' => '/var/www/vps-admin/api/.env', 'required' => false],
            ],
            'commands' => [
                ['cmd' => 'cat /var/www/vps-admin/api/composer.json 2>/dev/null | grep -E \'"version"|"name"\' | head -2', 'filename' => 'panel-info.txt'],
            ],
        ],
        'emailapp' => [
            'name' => 'MailFlow Email App',
            'check' => '/var/www/vps-email/backend/public/index.php',
            'files' => [
                ['path' => '/var/www/vps-email/backend/src/config.php', 'required' => false],
                ['path' => '/var/www/vps-email/backend/.env', 'required' => false],
            ],
            'commands' => [
                ['cmd' => 'cat /var/www/vps-email/backend/composer.json 2>/dev/null | grep -E \'"version"|"name"\' | head -2', 'filename' => 'email-info.txt'],
            ],
        ],
        'fleetmanager' => [
            'name' => 'Fleet Manager',
            'check' => '/var/www/vps-fleet/api/public/index.php',
            'files' => [
                ['path' => '/var/www/vps-fleet/api/config.local.php', 'required' => false],
                ['path' => '/var/www/vps-fleet/api/config.php', 'required' => false],
            ],
            'commands' => [
                ['cmd' => 'cat /var/www/vps-fleet/api/composer.json 2>/dev/null | grep -E \'"version"|"name"\' | head -2', 'filename' => 'fleet-info.txt'],
            ],
        ],
        
        // ============================================================
        // CUSTOM SERVICES - DEVCON Specific
        // ============================================================
        'collab_server' => [
            'name' => 'Collaboration Server',
            'check' => '/etc/systemd/system/collab-server.service',
            'files' => [
                ['path' => '/etc/systemd/system/collab-server.service', 'required' => false],
            ],
            'glob_patterns' => [
                '/opt/collab-server/*.json',
                '/opt/collab-server/config/*',
                '/var/www/collab-server/*.json',
            ],
            'commands' => [
                ['cmd' => 'systemctl status collab-server 2>/dev/null | head -10', 'filename' => 'collab-server-status.txt'],
            ],
        ],
        'fastapi_ssh' => [
            'name' => 'FastAPI SSH Server',
            'check' => '/etc/systemd/system/fastapi_ssh_server.service',
            'files' => [
                ['path' => '/etc/systemd/system/fastapi_ssh_server.service', 'required' => false],
            ],
            'glob_patterns' => [
                '/opt/fastapi-ssh/*.py',
                '/opt/fastapi-ssh/*.json',
                '/opt/fastapi-ssh/config/*',
                '/var/www/fastapi-ssh/*.py',
                '/var/www/fastapi-ssh/*.json',
            ],
            'commands' => [
                ['cmd' => 'systemctl status fastapi_ssh_server 2>/dev/null | head -10', 'filename' => 'fastapi-ssh-status.txt'],
                ['cmd' => 'cat /etc/systemd/system/fastapi_ssh_server.service 2>/dev/null | grep -E "^(ExecStart|WorkingDirectory|Environment)"', 'filename' => 'fastapi-ssh-config.txt'],
            ],
        ],
        'mailsync_server' => [
            'name' => 'Mail Sync Server',
            'check' => '/etc/systemd/system/mailsync-server.service',
            'files' => [
                ['path' => '/etc/systemd/system/mailsync-server.service', 'required' => false],
            ],
            'glob_patterns' => [
                '/opt/mailsync/*.json',
                '/opt/mailsync/*.php',
                '/opt/mailsync/config/*',
                '/var/www/mailsync/*.json',
                '/var/www/mailsync/*.php',
            ],
            'commands' => [
                ['cmd' => 'systemctl status mailsync-server 2>/dev/null | head -10', 'filename' => 'mailsync-status.txt'],
                ['cmd' => 'cat /etc/systemd/system/mailsync-server.service 2>/dev/null | grep -E "^(ExecStart|WorkingDirectory|Environment)"', 'filename' => 'mailsync-config.txt'],
            ],
        ],
        'lscpd' => [
            'name' => 'LiteSpeed Control Panel Daemon',
            'check' => '/etc/systemd/system/lscpd.service',
            'files' => [
                ['path' => '/etc/systemd/system/lscpd.service', 'required' => false],
                ['path' => '/usr/local/lscp/conf/lscpd.conf', 'required' => false],
            ],
            'directories' => [
                '/usr/local/lscp/conf',
            ],
            'glob_patterns' => [
                '/usr/local/lscp/conf/*.conf',
            ],
        ],
        
        // ============================================================
        // MONITORING & LOGGING
        // ============================================================
        'rsyslog' => [
            'name' => 'Rsyslog Logging',
            'files' => [
                ['path' => '/etc/rsyslog.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/rsyslog.d',
            ],
            'glob_patterns' => [
                '/etc/rsyslog.d/*.conf',
            ],
        ],
        
        // ============================================================
        // SYSTEM INFO (Reference)
        // ============================================================
        'system_info' => [
            'name' => 'System Information',
            'commands' => [
                ['cmd' => 'uname -a', 'filename' => 'uname.txt'],
                ['cmd' => 'cat /etc/os-release', 'filename' => 'os-release.txt'],
                ['cmd' => 'cat /etc/passwd', 'filename' => 'passwd.txt'],
                ['cmd' => 'cat /etc/group', 'filename' => 'group.txt'],
                ['cmd' => 'df -h', 'filename' => 'disk-usage.txt'],
                ['cmd' => 'free -h', 'filename' => 'memory.txt'],
                ['cmd' => 'lscpu', 'filename' => 'cpu-info.txt'],
                ['cmd' => 'cat /proc/meminfo', 'filename' => 'meminfo.txt'],
            ],
        ],
        'packages' => [
            'name' => 'Installed Packages',
            'commands' => [
                ['cmd' => 'dpkg --get-selections 2>/dev/null | head -500', 'filename' => 'dpkg-packages.txt'],
                ['cmd' => 'apt list --installed 2>/dev/null | head -500', 'filename' => 'apt-packages.txt'],
            ],
        ],
    ];

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Set SSH service instance (allows reusing existing connection)
     */
    public function setSSH(SSHService $ssh): self
    {
        $this->ssh = $ssh;
        return $this;
    }

    /**
     * Set specific categories to extract (for chunked extraction)
     */
    public function setCategories(array $categories): self
    {
        $this->categoriesToExtract = $categories;
        return $this;
    }

    /**
     * Get all available category keys
     */
    public static function getAllCategories(): array
    {
        return array_keys(self::EXTRACTION_MAP);
    }

    /**
     * Get or create SSH service
     */
    private function getSSH(): SSHService
    {
        if (!$this->ssh) {
            $this->ssh = $this->container->get(SSHService::class);
        }
        return $this->ssh;
    }

    /**
     * Execute a command (locally or via SSH)
     * For local mode, uses sudo if command needs root privileges
     */
    private function execCommand(string $command): array
    {
        if ($this->localMode) {
            // Execute locally with sudo for privileged commands
            $output = [];
            $exitCode = 0;
            // Prefix with sudo for commands that need elevated privileges
            $sudoCommand = "sudo " . $command . ' 2>&1';
            exec($sudoCommand, $output, $exitCode);
            return [
                'success' => $exitCode === 0,
                'output' => implode("\n", $output),
                'exit_code' => $exitCode,
            ];
        }
        return $this->getSSH()->exec($command);
    }

    /**
     * Check if file exists (locally or via SSH)
     */
    private function fileExistsCheck(string $path): bool
    {
        if ($this->localMode) {
            // Use sudo test -f for protected paths
            $result = $this->execCommand("test -f " . escapeshellarg($path));
            return $result['success'];
        }
        return $this->getSSH()->fileExists($path);
    }

    /**
     * Check if directory exists (locally or via SSH)
     */
    private function dirExistsCheck(string $path): bool
    {
        if ($this->localMode) {
            // Use sudo test -d for protected paths
            $result = $this->execCommand("test -d " . escapeshellarg($path));
            return $result['success'];
        }
        return $this->getSSH()->dirExists($path);
    }

    /**
     * Read file content (locally or via SSH)
     * Note: For local mode, uses sudo to read protected system files
     */
    private function readFileContent(string $path): ?string
    {
        if ($this->localMode) {
            // Try with sudo first (requires NOPASSWD for www-data)
            $output = [];
            $exitCode = 0;
            exec("sudo cat " . escapeshellarg($path) . " 2>/dev/null", $output, $exitCode);
            if ($exitCode === 0 && !empty($output)) {
                return implode("\n", $output);
            }
            // Fallback to direct read (for files www-data can access)
            if (file_exists($path) && is_readable($path)) {
                return file_get_contents($path);
            }
            return null;
        }
        $result = $this->getSSH()->exec("cat '{$path}'");
        return $result['success'] ? $result['output'] : null;
    }

    /**
     * Set dry run mode
     */
    public function setDryRun(bool $dryRun): self
    {
        $this->dryRun = $dryRun;
        return $this;
    }

    /**
     * Set local mode (extract from local server without SSH)
     */
    public function setLocalMode(bool $localMode): self
    {
        $this->localMode = $localMode;
        return $this;
    }

    /**
     * Get extraction log
     */
    public function getLog(): array
    {
        return $this->log;
    }

    /**
     * Clear log
     */
    public function clearLog(): void
    {
        $this->log = [];
    }

    /**
     * Add log entry
     */
    private function log(string $message, string $level = 'info', ?array $data = null): void
    {
        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
        ];
        
        if ($data !== null) {
            $entry['data'] = $data;
        }
        
        $this->log[] = $entry;
    }

    /**
     * Extract configs from a server (or preview what would be extracted)
     */
    public function extract(array $server, array $options = []): array
    {
        $this->clearLog();
        
        $isLocal = $this->localMode || ($server['is_local'] ?? false);
        
        $this->log('Starting config extraction', 'info', [
            'server' => $isLocal ? 'localhost' : ($server['name'] ?? $server['ip_address']),
            'mode' => $isLocal ? 'local' : 'remote',
            'dry_run' => $this->dryRun,
        ]);

        // For local mode, no SSH connection needed
        if ($isLocal) {
            $this->log('Local mode - extracting from this server directly', 'info');
        } elseif (!$this->dryRun) {
            // Connect to remote server
            $this->log('Connecting to server via SSH...');
            
            if (!$this->ssh->connectToServer($server)) {
                $this->log('Failed to connect to server', 'error');
                return [
                    'success' => false,
                    'error' => 'Failed to connect to server',
                    'log' => $this->log,
                ];
            }
            
            $this->log('Connected successfully', 'success');
        } else {
            $this->log('DRY RUN: Would connect to ' . $server['ip_address'], 'debug');
        }

        // Get server info
        $serverInfo = $this->getServerInfo();
        $this->log('Server info collected', 'info', $serverInfo);

        // Detect installed services
        $this->log('Detecting installed services...');
        $installedServices = $this->detectServices();
        $this->log('Services detected', 'info', ['services' => array_keys($installedServices)]);

        // Extract configurations
        $extractedConfigs = [];
        $errors = [];
        $skipped = [];

        // Determine which categories to extract
        $categoriesToProcess = $this->categoriesToExtract ?? array_keys(self::EXTRACTION_MAP);
        
        $this->log('Categories to extract: ' . count($categoriesToProcess), 'info', [
            'chunked' => $this->categoriesToExtract !== null,
            'categories' => $categoriesToProcess,
        ]);

        foreach (self::EXTRACTION_MAP as $category => $config) {
            // Skip if not in the categories to extract (for chunked extraction)
            if (!in_array($category, $categoriesToProcess)) {
                continue;
            }

            $categoryName = $config['name'] ?? $category;
            
            // Check if service is installed (unless it's always extracted)
            if (isset($config['check'])) {
                if (!isset($installedServices[$category]) || !$installedServices[$category]) {
                    $this->log("Skipping {$categoryName} - not installed", 'debug');
                    $skipped[$category] = 'Service not installed';
                    continue;
                }
            }

            $this->log("Extracting {$categoryName} configs...");
            
            $categoryConfigs = $this->extractCategory($category, $config);
            
            if (!empty($categoryConfigs['files'])) {
                $extractedConfigs[$category] = $categoryConfigs;
                $this->log("Extracted " . count($categoryConfigs['files']) . " files from {$categoryName}", 'success');
            }
            
            if (!empty($categoryConfigs['errors'])) {
                $errors[$category] = $categoryConfigs['errors'];
            }
        }

        // Extract installed packages
        $this->log('Extracting installed packages...');
        $packages = $this->extractInstalledPackages();
        $this->log('Packages extracted', 'info', ['total' => count($packages['all'] ?? [])]);

        // Disconnect (only for remote servers)
        if (!$this->dryRun && !$isLocal && $this->ssh) {
            $this->ssh->disconnect();
            $this->log('Disconnected from server');
        }

        $result = [
            'success' => true,
            'dry_run' => $this->dryRun,
            'server_info' => $serverInfo,
            'installed_services' => $installedServices,
            'extracted' => $extractedConfigs,
            'packages' => $packages,
            'skipped' => $skipped,
            'errors' => $errors,
            'summary' => $this->generateSummary($extractedConfigs, $skipped, $errors),
            'log' => $this->log,
        ];

        $this->log('Extraction complete', 'success', ['summary' => $result['summary']]);

        return $result;
    }

    /**
     * Get server information
     */
    private function getServerInfo(): array
    {
        if ($this->dryRun) {
            return [
                'hostname' => '[DRY RUN]',
                'os' => '[Would be detected]',
                'kernel' => '[Would be detected]',
                'ip' => '[Would be detected]',
            ];
        }

        return [
            'hostname' => trim($this->execCommand('hostname')['output'] ?? ''),
            'os' => trim($this->execCommand('cat /etc/os-release | grep PRETTY_NAME | cut -d\'"\' -f2')['output'] ?? ''),
            'kernel' => trim($this->execCommand('uname -r')['output'] ?? ''),
            'ip' => trim($this->execCommand('hostname -I | awk \'{print $1}\'')['output'] ?? ''),
        ];
    }

    /**
     * Detect which services are installed
     */
    private function detectServices(): array
    {
        $services = [];

        foreach (self::EXTRACTION_MAP as $category => $config) {
            if (!isset($config['check'])) {
                $services[$category] = true; // Always include if no check
                continue;
            }

            if ($this->dryRun) {
                $services[$category] = true; // Assume installed in dry run
                $this->log("DRY RUN: Would check for {$config['check']}", 'debug');
            } else {
                $services[$category] = $this->fileExistsCheck($config['check']);
                $this->log("Checked {$category}: " . ($services[$category] ? 'installed' : 'not found'), 'debug');
            }
        }

        return $services;
    }

    /**
     * Extract configs for a category
     */
    private function extractCategory(string $category, array $config): array
    {
        $files = [];
        $errors = [];

        // Extract specific files
        if (isset($config['files'])) {
            foreach ($config['files'] as $fileConfig) {
                $path = $fileConfig['path'];
                $required = $fileConfig['required'] ?? false;

                $result = $this->extractFile($path);
                
                if ($result['success']) {
                    $files[] = $result;
                } elseif ($required) {
                    $errors[] = "Required file not found: {$path}";
                    $this->log("Required file not found: {$path}", 'warning');
                }
            }
        }

        // Extract directories
        if (isset($config['directories'])) {
            foreach ($config['directories'] as $dir) {
                $dirFiles = $this->extractDirectory($dir);
                $files = array_merge($files, $dirFiles);
            }
        }

        // Extract glob patterns
        if (isset($config['glob_patterns'])) {
            foreach ($config['glob_patterns'] as $pattern) {
                $globFiles = $this->extractGlob($pattern);
                $files = array_merge($files, $globFiles);
            }
        }

        // Run commands
        if (isset($config['commands'])) {
            foreach ($config['commands'] as $cmdConfig) {
                $result = $this->extractCommand($cmdConfig['cmd'], $cmdConfig['filename']);
                if ($result['success'] && !empty($result['content'])) {
                    $files[] = $result;
                }
            }
        }

        return [
            'name' => $config['name'] ?? $category,
            'files' => $files,
            'errors' => $errors,
        ];
    }

    /**
     * Extract a single file
     */
    private function extractFile(string $path): array
    {
        $this->log("Extracting file: {$path}", 'debug');

        if ($this->dryRun) {
            return [
                'success' => true,
                'path' => $path,
                'filename' => basename($path),
                'content' => '[DRY RUN - Content would be extracted]',
                'size' => 0,
                'dry_run' => true,
            ];
        }

        // Check if file exists
        if (!$this->fileExistsCheck($path)) {
            return ['success' => false, 'path' => $path, 'error' => 'File not found'];
        }

        // Get file content
        $content = $this->readFileContent($path);
        if ($content === null) {
            return ['success' => false, 'path' => $path, 'error' => 'Failed to read file'];
        }

        // Get file info
        $statResult = $this->execCommand("stat -c '%a %U %G %s' '{$path}'");
        $statParts = explode(' ', trim($statResult['output'] ?? ''));

        return [
            'success' => true,
            'path' => $path,
            'filename' => basename($path),
            'content' => $content,
            'size' => (int)($statParts[3] ?? 0),
            'permissions' => $statParts[0] ?? '0644',
            'owner' => $statParts[1] ?? 'root',
            'group' => $statParts[2] ?? 'root',
        ];
    }

    /**
     * Extract all files from a directory
     */
    private function extractDirectory(string $dir): array
    {
        $files = [];

        $this->log("Extracting directory: {$dir}", 'debug');

        if ($this->dryRun) {
            return [[
                'success' => true,
                'path' => $dir . '/*',
                'filename' => '[directory contents]',
                'content' => '[DRY RUN - Directory would be extracted]',
                'dry_run' => true,
            ]];
        }

        // Check if directory exists
        if (!$this->dirExistsCheck($dir)) {
            $this->log("Directory not found: {$dir}", 'debug');
            return [];
        }

        // List files in directory
        $result = $this->execCommand("find '{$dir}' -type f \\( -name '*.conf' -o -name '*.cf' -o -name '*.local' \\) 2>/dev/null");
        if (!$result['success'] || empty($result['output'])) {
            return [];
        }

        $filePaths = array_filter(explode("\n", trim($result['output'])));
        
        foreach ($filePaths as $filePath) {
            $fileResult = $this->extractFile($filePath);
            if ($fileResult['success']) {
                $files[] = $fileResult;
            }
        }

        return $files;
    }

    /**
     * Extract files matching a glob pattern
     */
    private function extractGlob(string $pattern): array
    {
        $files = [];

        $this->log("Extracting glob pattern: {$pattern}", 'debug');

        if ($this->dryRun) {
            return [[
                'success' => true,
                'path' => $pattern,
                'filename' => '[glob pattern]',
                'content' => '[DRY RUN - Matching files would be extracted]',
                'dry_run' => true,
            ]];
        }

        // Find matching files
        $result = $this->execCommand("ls {$pattern} 2>/dev/null || true");
        if (empty($result['output'])) {
            return [];
        }

        $filePaths = array_filter(explode("\n", trim($result['output'])));
        
        foreach ($filePaths as $filePath) {
            $fileResult = $this->extractFile($filePath);
            if ($fileResult['success']) {
                $files[] = $fileResult;
            }
        }

        return $files;
    }

    /**
     * Extract output of a command
     */
    private function extractCommand(string $command, string $filename): array
    {
        $this->log("Running command: {$command}", 'debug');

        if ($this->dryRun) {
            return [
                'success' => true,
                'path' => "[command: {$command}]",
                'filename' => $filename,
                'content' => '[DRY RUN - Command output would be captured]',
                'dry_run' => true,
            ];
        }

        $result = $this->execCommand($command);
        
        return [
            'success' => true,
            'path' => "[command: {$command}]",
            'filename' => $filename,
            'content' => $result['output'] ?? '',
            'size' => strlen($result['output'] ?? ''),
        ];
    }

    /**
     * Generate extraction summary
     */
    private function generateSummary(array $extracted, array $skipped, array $errors): array
    {
        $totalFiles = 0;
        $totalSize = 0;
        $categories = [];

        foreach ($extracted as $category => $data) {
            $fileCount = count($data['files'] ?? []);
            $categorySize = array_sum(array_column($data['files'] ?? [], 'size'));
            
            $totalFiles += $fileCount;
            $totalSize += $categorySize;
            
            $categories[$category] = [
                'name' => $data['name'],
                'files' => $fileCount,
                'size' => $categorySize,
            ];
        }

        return [
            'total_files' => $totalFiles,
            'total_size' => $totalSize,
            'total_size_human' => $this->formatBytes($totalSize),
            'categories_extracted' => count($extracted),
            'categories_skipped' => count($skipped),
            'errors_count' => count($errors),
            'categories' => $categories,
        ];
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1048576) return round($bytes / 1024, 2) . ' KB';
        return round($bytes / 1048576, 2) . ' MB';
    }

    /**
     * Extract installed packages from the server
     * Groups packages by category for use in blueprints
     */
    private function extractInstalledPackages(): array
    {
        if ($this->dryRun) {
            return [
                'all' => ['[DRY RUN - Packages would be extracted]'],
                'by_category' => [
                    'base' => [],
                    'web' => [],
                    'php' => [],
                    'database' => [],
                    'mail' => [],
                    'security' => [],
                ],
                'dry_run' => true,
            ];
        }

        // Package patterns for categorization
        $categoryPatterns = [
            'base' => [
                'curl', 'wget', 'git', 'unzip', 'zip', 'software-properties-common',
                'apt-transport-https', 'ca-certificates', 'gnupg', 'lsb-release',
                'htop', 'vim', 'nano', 'net-tools', 'dnsutils',
            ],
            'web' => [
                'openlitespeed', 'nginx*', 'apache2*', 'certbot',
            ],
            'php' => [
                'lsphp*', 'php*-fpm', 'php*-cli', 'php*-common', 'php*-mysql',
                'php*-curl', 'php*-imap', 'php*-intl', 'php*-opcache', 'php*-mbstring',
                'php*-xml', 'php*-gd', 'php*-zip', 'php*-bcmath',
            ],
            'database' => [
                'mariadb-server', 'mariadb-client', 'mysql-server', 'mysql-client',
                'redis-server', 'memcached',
            ],
            'mail' => [
                'postfix*', 'dovecot*', 'spamassassin', 'opendkim*', 'clamav*',
            ],
            'security' => [
                'fail2ban', 'firewalld', 'ufw', 'iptables*', 'modsecurity*',
            ],
        ];

        // Get manually installed packages
        $result = $this->execCommand('apt-mark showmanual 2>/dev/null || true');
        $manualPackages = array_filter(explode("\n", trim($result['output'] ?? '')));

        // Also get essential installed packages
        $result = $this->execCommand("dpkg-query -W -f='\${Package}\\n' 2>/dev/null | head -500");
        $allPackages = array_filter(explode("\n", trim($result['output'] ?? '')));

        // Categorize packages
        $byCategory = [
            'base' => [],
            'web' => [],
            'php' => [],
            'database' => [],
            'mail' => [],
            'security' => [],
            'other' => [],
        ];

        foreach ($manualPackages as $package) {
            $package = trim($package);
            if (empty($package)) continue;

            $categorized = false;

            foreach ($categoryPatterns as $category => $patterns) {
                foreach ($patterns as $pattern) {
                    // Convert glob pattern to regex
                    $regex = '/^' . str_replace('*', '.*', preg_quote($pattern, '/')) . '$/';
                    if (preg_match($regex, $package)) {
                        $byCategory[$category][] = $package;
                        $categorized = true;
                        break 2;
                    }
                }
            }

            // Don't add uncategorized packages to 'other' to keep it clean
            // They can be manually added if needed
        }

        // Sort packages within each category
        foreach ($byCategory as &$packages) {
            sort($packages);
            $packages = array_unique($packages);
        }

        // Get package versions for important packages
        $packageVersions = [];
        $importantPackages = array_merge(
            $byCategory['web'],
            $byCategory['database'],
            array_slice($byCategory['php'], 0, 3) // First 3 PHP packages
        );

        foreach ($importantPackages as $pkg) {
            $versionResult = $this->execCommand("dpkg-query -W -f='\${Version}' {$pkg} 2>/dev/null || true");
            $version = trim($versionResult['output'] ?? '');
            if (!empty($version)) {
                $packageVersions[$pkg] = $version;
            }
        }

        return [
            'all' => $manualPackages,
            'by_category' => $byCategory,
            'versions' => $packageVersions,
            'total_manual' => count($manualPackages),
            'total_system' => count($allPackages),
        ];
    }

    /**
     * Convert extracted configs to blueprint templates
     * Replaces detected sensitive values with template variables
     */
    public function convertToTemplates(array $extracted, array $variables): array
    {
        $templates = [];

        // Debug: log what variables we're going to replace
        error_log("convertToTemplates: Variables to replace: " . json_encode(array_keys($variables)));
        foreach ($variables as $k => $v) {
            error_log("  {$k} => " . (strlen($v) > 20 ? substr($v, 0, 20) . '...' : $v));
        }

        // Sort variables by value length (longest first) to avoid partial replacements
        // e.g., "mailserver_db" should be replaced before "mailserver"
        $sortedVariables = $variables;
        uasort($sortedVariables, function($a, $b) {
            $lenA = is_string($a) ? strlen($a) : 0;
            $lenB = is_string($b) ? strlen($b) : 0;
            return $lenB - $lenA;
        });

        foreach ($extracted as $category => $data) {
            foreach ($data['files'] ?? [] as $file) {
                if (empty($file['content']) || ($file['dry_run'] ?? false)) {
                    continue;
                }

                $content = $file['content'];
                $originalContent = $content;

                // Replace known values with variables
                foreach ($sortedVariables as $varName => $varValue) {
                    if (!empty($varValue) && is_string($varValue) && strlen($varValue) >= 3) {
                        // Use word boundary-aware replacement where possible
                        // But don't break config files that may have passwords embedded in URLs etc.
                        $escapedValue = preg_quote($varValue, '/');
                        
                        // Replace the value with {{VARIABLE_NAME}} placeholder
                        $newContent = preg_replace(
                            '/' . $escapedValue . '/',
                            '{{' . $varName . '}}',
                            $content
                        );
                        
                        if ($newContent !== $content) {
                            error_log("convertToTemplates: Replaced '{$varValue}' with {{{$varName}}} in " . ($file['path'] ?? $file['filename']));
                        }
                        $content = $newContent;
                    }
                }

                // Also handle common path patterns that should be variables
                $content = $this->replacePathPatterns($content, $category);

                $templates[] = [
                    'category' => $category,
                    'filename' => $file['filename'],
                    'target_path' => $file['path'],
                    'content' => $content,
                    'permissions' => $file['permissions'] ?? '0644',
                    'owner' => $file['owner'] ?? 'root',
                    'group' => $file['group'] ?? 'root',
                    'original_size' => $file['size'] ?? 0,
                    'variables_used' => $this->detectUsedVariables($content),
                ];
            }
        }

        return $templates;
    }

    /**
     * Replace common path patterns with variables
     */
    private function replacePathPatterns(string $content, string $category): string
    {
        // SSL certificate paths - replace domain-specific paths with variable
        $content = preg_replace(
            '#(/etc/letsencrypt/live/)([a-z0-9][a-z0-9.-]+\.[a-z]{2,})(/(?:fullchain|privkey|cert|chain)\.pem)#i',
            '$1{{MAIL_DOMAIN}}$3',
            $content
        );

        return $content;
    }

    /**
     * Detect which variables are used in content
     */
    private function detectUsedVariables(string $content): array
    {
        preg_match_all('/\{\{\s*([A-Z_][A-Z0-9_]*)\s*\}\}/', $content, $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Convert extracted packages to blueprint package format
     */
    public function convertToPackages(array $packages): array
    {
        $blueprintPackages = [];
        $order = 0;

        foreach ($packages['by_category'] ?? [] as $category => $pkgList) {
            foreach ($pkgList as $pkg) {
                $blueprintPackages[] = [
                    'category' => $category,
                    'package_name' => $pkg,
                    'version_constraint' => null, // Can be set from versions data
                    'is_required' => true,
                    'install_order' => $order++,
                ];
            }
        }

        return $blueprintPackages;
    }
}

