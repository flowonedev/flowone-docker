<?php

namespace FleetManager\Agent\Actions;

use FleetManager\Agent\Lib\BaseAction;

/**
 * Config Extractor Action
 * 
 * Extracts server configurations for blueprint creation.
 * Runs as root so has full access to all config files.
 */
class ExtractorAction extends BaseAction
{
    private array $log = [];
    
    // Extraction modes
    public const MODE_FULL_CLONE = 'full_clone';       // Everything - for server migration
    public const MODE_BASE_CONFIG = 'base_config';     // Generic configs only - for new server
    
    // Category types for filtering
    public const TYPE_GENERIC = 'generic';              // Always included
    public const TYPE_DOMAIN_SPECIFIC = 'domain_specific'; // Only in full_clone
    public const TYPE_VHOST = 'vhost';                  // Special handling for vhosts
    public const TYPE_CORE_APP = 'core_app';            // Core apps (panel, email, fleet)
    
    // Category classification - which categories are domain-specific
    private const CATEGORY_TYPES = [
        // Generic configs - always included
        'modsecurity' => self::TYPE_GENERIC,
        'php' => self::TYPE_GENERIC,
        'mariadb' => self::TYPE_GENERIC,
        'postfix' => self::TYPE_GENERIC,
        'dovecot' => self::TYPE_GENERIC,
        'spamassassin' => self::TYPE_GENERIC,
        'rspamd' => self::TYPE_GENERIC,
        'clamav' => self::TYPE_GENERIC,
        'fail2ban' => self::TYPE_GENERIC,
        'cpguard' => self::TYPE_GENERIC,
        'firewalld' => self::TYPE_GENERIC,
        'iptables' => self::TYPE_GENERIC,
        'openvpn' => self::TYPE_GENERIC,
        'wireguard' => self::TYPE_GENERIC,
        'redis' => self::TYPE_GENERIC,
        'lsmcd' => self::TYPE_GENERIC,
        'ssh' => self::TYPE_GENERIC,
        'pam' => self::TYPE_GENERIC,
        'sudo' => self::TYPE_GENERIC,
        'sysctl' => self::TYPE_GENERIC,
        'limits' => self::TYPE_GENERIC,
        'systemd' => self::TYPE_GENERIC,
        'cron' => self::TYPE_GENERIC,
        'logrotate' => self::TYPE_GENERIC,
        'network' => self::TYPE_GENERIC,
        'rsyslog' => self::TYPE_GENERIC,
        'system_info' => self::TYPE_GENERIC,
        'packages' => self::TYPE_GENERIC,
        
        // Domain-specific - only in full_clone mode
        'letsencrypt' => self::TYPE_DOMAIN_SPECIFIC,
        'opendkim' => self::TYPE_DOMAIN_SPECIFIC,
        'opendmarc' => self::TYPE_DOMAIN_SPECIFIC,
        
        // VHosts - special handling (filter by selected domains)
        'openlitespeed' => self::TYPE_VHOST,
        
        // Core apps - can be toggled individually
        'panel' => self::TYPE_CORE_APP,
        'emailapp' => self::TYPE_CORE_APP,
        'fleetmanager' => self::TYPE_CORE_APP,
        'collab_server' => self::TYPE_CORE_APP,
        'fastapi_ssh' => self::TYPE_CORE_APP,
        'mailsync_server' => self::TYPE_CORE_APP,
        'lscpd' => self::TYPE_CORE_APP,
    ];
    
    // Core app vhost identifiers (used to filter vhosts in base_config mode)
    private const CORE_APP_VHOSTS = [
        'panel' => ['panel.devcon1.hu', 'panel'],
        'emailapp' => ['email.devcon1.hu', 'email'],
        'fleetmanager' => ['fleet.devcon1.hu', 'fleet'],
    ];
    
    // Comprehensive extraction map - same as API but runs with root privileges
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
            'directories' => [
                '/usr/local/lsws/conf/vhosts',
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
                ['path' => '/etc/postfix/transport', 'required' => false],
                ['path' => '/etc/aliases', 'required' => false],
                ['path' => '/etc/mailname', 'required' => false],
            ],
            'glob_patterns' => [
                '/etc/postfix/*.cf',
                '/etc/postfix/mysql-*.cf',
                '/etc/postfix/virtual*',
                '/etc/postfix/sasl/*',
            ],
            'commands' => [
                ['cmd' => 'postconf -n 2>/dev/null', 'filename' => 'postconf-non-default.txt'],
                ['cmd' => 'postconf mail_version 2>/dev/null | head -1', 'filename' => 'postfix-version.txt'],
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
            ],
            'directories' => [
                '/etc/dovecot/conf.d',
                '/etc/dovecot/sieve',
            ],
            'glob_patterns' => [
                '/etc/dovecot/*.conf',
                '/etc/dovecot/conf.d/*.conf',
            ],
            'commands' => [
                ['cmd' => 'dovecot --version 2>&1', 'filename' => 'dovecot-version.txt'],
                ['cmd' => 'doveconf -n 2>/dev/null', 'filename' => 'doveconf-non-default.txt'],
            ],
        ],
        
        // ============================================================
        // MAIL - DKIM/DMARC
        // ============================================================
        'opendkim' => [
            'name' => 'OpenDKIM Email Signing',
            'check' => '/usr/sbin/opendkim',
            'files' => [
                ['path' => '/etc/opendkim.conf', 'required' => false],
                ['path' => '/etc/default/opendkim', 'required' => false],
                ['path' => '/etc/opendkim/KeyTable', 'required' => false],
                ['path' => '/etc/opendkim/SigningTable', 'required' => false],
                ['path' => '/etc/opendkim/TrustedHosts', 'required' => false],
            ],
            'directories' => [
                '/etc/opendkim',
                '/etc/opendkim/keys',
            ],
            'glob_patterns' => [
                '/etc/opendkim/*.conf',
                '/etc/opendkim/keys/*/*.txt',
            ],
        ],
        'opendmarc' => [
            'name' => 'OpenDMARC',
            'check' => '/usr/sbin/opendmarc',
            'files' => [
                ['path' => '/etc/opendmarc.conf', 'required' => false],
                ['path' => '/etc/default/opendmarc', 'required' => false],
            ],
        ],
        
        // ============================================================
        // MAIL - Spam/Antivirus
        // ============================================================
        'spamassassin' => [
            'name' => 'SpamAssassin',
            'check' => '/usr/bin/spamassassin',
            'files' => [
                ['path' => '/etc/spamassassin/local.cf', 'required' => false],
            ],
            'directories' => [
                '/etc/spamassassin',
            ],
        ],
        'rspamd' => [
            'name' => 'Rspamd Spam Filter',
            'check' => '/usr/bin/rspamd',
            'directories' => [
                '/etc/rspamd',
                '/etc/rspamd/local.d',
            ],
        ],
        'clamav' => [
            'name' => 'ClamAV Antivirus',
            'check' => '/usr/bin/clamscan',
            'files' => [
                ['path' => '/etc/clamav/clamd.conf', 'required' => false],
                ['path' => '/etc/clamav/freshclam.conf', 'required' => false],
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
                '/etc/fail2ban/filter.d/*.conf',
            ],
            'commands' => [
                ['cmd' => 'fail2ban-client status 2>/dev/null', 'filename' => 'fail2ban-status.txt'],
            ],
        ],
        
        // ============================================================
        // SECURITY - CPGuard
        // ============================================================
        'cpguard' => [
            'name' => 'CPGuard Security',
            'check' => '/usr/local/cpguard/cpguard',
            'directories' => [
                '/etc/cpguard',
                '/usr/local/cpguard/etc',
            ],
            'glob_patterns' => [
                '/etc/cpguard/*.conf',
                '/usr/local/cpguard/etc/*.conf',
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
            ],
            'glob_patterns' => [
                '/etc/firewalld/*.conf',
                '/etc/firewalld/zones/*.xml',
                '/etc/firewalld/services/*.xml',
            ],
            'commands' => [
                ['cmd' => 'firewall-cmd --list-all 2>/dev/null', 'filename' => 'firewall-rules.txt'],
            ],
        ],
        'iptables' => [
            'name' => 'IPTables Rules',
            'files' => [
                ['path' => '/etc/iptables/rules.v4', 'required' => false],
                ['path' => '/etc/iptables/rules.v6', 'required' => false],
            ],
            'commands' => [
                ['cmd' => 'iptables -L -n -v 2>/dev/null', 'filename' => 'iptables-current.txt'],
            ],
        ],
        
        // ============================================================
        // VPN
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
        // CACHING
        // ============================================================
        'redis' => [
            'name' => 'Redis Cache Server',
            'check' => '/usr/bin/redis-server',
            'files' => [
                ['path' => '/etc/redis/redis.conf', 'required' => false],
                ['path' => '/etc/redis.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/redis',
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
        ],
        
        // ============================================================
        // SSH & SYSTEM
        // ============================================================
        'ssh' => [
            'name' => 'SSH Server',
            'check' => '/usr/sbin/sshd',
            'files' => [
                ['path' => '/etc/ssh/sshd_config', 'required' => true],
                ['path' => '/etc/ssh/ssh_config', 'required' => false],
            ],
            'directories' => [
                '/etc/ssh/sshd_config.d',
            ],
        ],
        'pam' => [
            'name' => 'PAM Authentication',
            'files' => [
                ['path' => '/etc/pam.d/common-auth', 'required' => false],
                ['path' => '/etc/pam.d/sshd', 'required' => false],
                ['path' => '/etc/pam.d/sudo', 'required' => false],
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
        ],
        'sysctl' => [
            'name' => 'Kernel Parameters (sysctl)',
            'files' => [
                ['path' => '/etc/sysctl.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/sysctl.d',
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
        ],
        'systemd' => [
            'name' => 'Custom Systemd Services',
            'glob_patterns' => [
                '/etc/systemd/system/*.service',
                '/etc/systemd/system/*.timer',
            ],
            'commands' => [
                ['cmd' => 'systemctl list-unit-files --state=enabled 2>/dev/null', 'filename' => 'systemd-enabled.txt'],
            ],
        ],
        'cron' => [
            'name' => 'Scheduled Tasks (Cron)',
            'files' => [
                ['path' => '/etc/crontab', 'required' => false],
            ],
            'directories' => [
                '/etc/cron.d',
                '/etc/cron.daily',
                '/etc/cron.hourly',
            ],
            'commands' => [
                ['cmd' => 'crontab -l 2>/dev/null || echo "No crontab"', 'filename' => 'root-crontab.txt'],
            ],
        ],
        'logrotate' => [
            'name' => 'Log Rotation',
            'files' => [
                ['path' => '/etc/logrotate.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/logrotate.d',
            ],
        ],
        'letsencrypt' => [
            'name' => 'Let\'s Encrypt SSL',
            'check' => '/usr/bin/certbot',
            'files' => [
                ['path' => '/etc/letsencrypt/cli.ini', 'required' => false],
            ],
            'directories' => [
                '/etc/letsencrypt/renewal',
            ],
            'commands' => [
                ['cmd' => 'certbot certificates 2>/dev/null', 'filename' => 'certbot-certificates.txt'],
            ],
        ],
        'network' => [
            'name' => 'Network Configuration',
            'files' => [
                ['path' => '/etc/hosts', 'required' => false],
                ['path' => '/etc/hostname', 'required' => false],
                ['path' => '/etc/resolv.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/netplan',
            ],
            'glob_patterns' => [
                '/etc/netplan/*.yaml',
            ],
            'commands' => [
                ['cmd' => 'ip addr show', 'filename' => 'ip-addresses.txt'],
                ['cmd' => 'ss -tlnp', 'filename' => 'listening-ports.txt'],
            ],
        ],
        
        // ============================================================
        // DEVCON APPS
        // ============================================================
        'panel' => [
            'name' => 'DEVCON VPS Panel',
            'check' => '/var/www/vps-admin/api/public/index.php',
            'files' => [
                ['path' => '/var/www/vps-admin/api/config.local.php', 'required' => false],
                ['path' => '/var/www/vps-admin/api/config.php', 'required' => false],
            ],
        ],
        'emailapp' => [
            'name' => 'MailFlow Email App',
            'check' => '/var/www/vps-email/backend/public/index.php',
            'files' => [
                ['path' => '/var/www/vps-email/backend/src/config.php', 'required' => false],
            ],
        ],
        'fleetmanager' => [
            'name' => 'Fleet Manager',
            'check' => '/var/www/vps-fleet/api/public/index.php',
            'files' => [
                ['path' => '/var/www/vps-fleet/api/config.local.php', 'required' => false],
                ['path' => '/var/www/vps-fleet/api/config.php', 'required' => false],
            ],
        ],
        
        // ============================================================
        // CUSTOM SERVICES
        // ============================================================
        'collab_server' => [
            'name' => 'Collaboration Server',
            'check' => '/etc/systemd/system/collab-server.service',
            'files' => [
                ['path' => '/etc/systemd/system/collab-server.service', 'required' => false],
            ],
        ],
        'fastapi_ssh' => [
            'name' => 'FastAPI SSH Server',
            'check' => '/etc/systemd/system/fastapi_ssh_server.service',
            'files' => [
                ['path' => '/etc/systemd/system/fastapi_ssh_server.service', 'required' => false],
            ],
        ],
        'mailsync_server' => [
            'name' => 'Mail Sync Server',
            'check' => '/etc/systemd/system/mailsync-server.service',
            'files' => [
                ['path' => '/etc/systemd/system/mailsync-server.service', 'required' => false],
            ],
        ],
        'lscpd' => [
            'name' => 'LiteSpeed Control Panel Daemon',
            'check' => '/etc/systemd/system/lscpd.service',
            'files' => [
                ['path' => '/etc/systemd/system/lscpd.service', 'required' => false],
            ],
        ],
        'rsyslog' => [
            'name' => 'Rsyslog Logging',
            'files' => [
                ['path' => '/etc/rsyslog.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/rsyslog.d',
            ],
        ],
        
        // ============================================================
        // SYSTEM INFO
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
            ],
        ],
        'packages' => [
            'name' => 'Installed Packages',
            'commands' => [
                ['cmd' => 'dpkg --get-selections 2>/dev/null | head -500', 'filename' => 'dpkg-packages.txt'],
                ['cmd' => 'apt list --installed 2>/dev/null | head -500', 'filename' => 'apt-packages.txt'],
            ],
        ],

        // ============================================================
        // DNS
        // ============================================================
        'powerdns' => [
            'name' => 'PowerDNS Authoritative Server',
            'check' => '/usr/sbin/pdns_server',
            'files' => [
                ['path' => '/etc/powerdns/pdns.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/powerdns/pdns.d',
            ],
            'commands' => [
                ['cmd' => 'pdns_control version 2>/dev/null || echo "n/a"', 'filename' => 'pdns-version.txt'],
            ],
        ],

        // ============================================================
        // SEARCH
        // ============================================================
        'meilisearch' => [
            'name' => 'Meilisearch Search Engine',
            'check' => '/usr/local/bin/meilisearch',
            'files' => [
                ['path' => '/etc/meilisearch.toml', 'required' => false],
                ['path' => '/etc/systemd/system/meilisearch.service', 'required' => false],
            ],
        ],

        // ============================================================
        // REALTIME / WEBRTC
        // ============================================================
        'coturn' => [
            'name' => 'coTURN STUN/TURN Server',
            'check' => '/usr/bin/turnserver',
            'files' => [
                ['path' => '/etc/turnserver.conf', 'required' => false],
                ['path' => '/etc/default/coturn', 'required' => false],
            ],
        ],
        'livekit' => [
            'name' => 'LiveKit WebRTC Server',
            'check' => '/usr/local/bin/livekit-server',
            'files' => [
                ['path' => '/etc/livekit/livekit.yaml', 'required' => false],
                ['path' => '/etc/systemd/system/livekit-server.service', 'required' => false],
            ],
        ],
        'nghttpx' => [
            'name' => 'nghttpx HTTP/2 Proxy',
            'check' => '/etc/nghttpx',
            'files' => [
                ['path' => '/etc/nghttpx/nghttpx.conf', 'required' => false],
                ['path' => '/etc/nghttpx.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/nghttpx',
            ],
        ],
        'stunnel' => [
            'name' => 'stunnel TLS Tunnel',
            'check' => '/usr/bin/stunnel4',
            'files' => [
                ['path' => '/etc/default/stunnel4', 'required' => false],
            ],
            'directories' => [
                '/etc/stunnel',
            ],
            'glob_patterns' => [
                '/etc/stunnel/*.conf',
            ],
        ],

        // ============================================================
        // HOST SECURITY / AUDIT
        // ============================================================
        'auditd' => [
            'name' => 'Linux Audit Daemon',
            'check' => '/sbin/auditd',
            'files' => [
                ['path' => '/etc/audit/auditd.conf', 'required' => false],
                ['path' => '/etc/audit/audit.rules', 'required' => false],
            ],
            'directories' => [
                '/etc/audit/rules.d',
            ],
            'commands' => [
                ['cmd' => 'auditctl -l 2>/dev/null | head -200', 'filename' => 'auditctl-rules.txt'],
            ],
        ],
        'rkhunter' => [
            'name' => 'Rootkit Hunter',
            'check' => '/usr/bin/rkhunter',
            'files' => [
                ['path' => '/etc/rkhunter.conf', 'required' => false],
                ['path' => '/etc/rkhunter.conf.local', 'required' => false],
            ],
            'directories' => [
                '/etc/rkhunter.d',
            ],
        ],
        'aide' => [
            'name' => 'AIDE File Integrity',
            'check' => '/usr/bin/aide',
            'files' => [
                ['path' => '/etc/aide/aide.conf', 'required' => false],
                ['path' => '/etc/aide.conf', 'required' => false],
            ],
            'directories' => [
                '/etc/aide/aide.conf.d',
            ],
        ],

        // ============================================================
        // PHP RUNTIME VERSIONS
        // ============================================================
        'php_versions' => [
            'name' => 'Installed PHP Versions (lsphp)',
            'commands' => [
                ['cmd' => 'ls -1d /usr/local/lsws/lsphp* 2>/dev/null', 'filename' => 'lsphp-dirs.txt'],
                ['cmd' => 'dpkg -l 2>/dev/null | grep -E "^ii.*lsphp"', 'filename' => 'lsphp-packages.txt'],
                ['cmd' => 'grep -rhoE "lsphp[0-9]{2}" /usr/local/lsws/conf/vhosts 2>/dev/null | sort | uniq -c', 'filename' => 'lsphp-vhost-usage.txt'],
            ],
        ],

        // ============================================================
        // FLOWONE SHARED LIBRARY
        // ============================================================
        'flowone_shared' => [
            'name' => 'FlowOne Shared Library',
            'check' => '/var/www/shared',
            'commands' => [
                ['cmd' => 'ls -1 /var/www/shared 2>/dev/null', 'filename' => 'shared-toplevel.txt'],
                ['cmd' => 'cat /var/www/shared/composer.json 2>/dev/null', 'filename' => 'shared-composer.txt'],
            ],
        ],
    ];
    
    public function getNamespace(): string
    {
        return 'extractor';
    }
    
    /**
     * Get all categories for chunked extraction
     */
    public function actionCategories(array $params, string $actor): array
    {
        $mode = $params['mode'] ?? self::MODE_FULL_CLONE;
        
        $categories = [];
        $categoryDetails = [];
        
        foreach (self::EXTRACTION_MAP as $key => $config) {
            $type = self::CATEGORY_TYPES[$key] ?? self::TYPE_GENERIC;
            
            $categories[$key] = $config['name'];
            $categoryDetails[$key] = [
                'name' => $config['name'],
                'type' => $type,
                'included_in_base' => in_array($type, [self::TYPE_GENERIC, self::TYPE_CORE_APP]),
            ];
        }
        
        // Create chunks of ~8 categories each
        $allKeys = array_keys($categories);
        $chunkSize = 8;
        $chunks = [];
        
        for ($i = 0; $i < count($allKeys); $i += $chunkSize) {
            $chunkKeys = array_slice($allKeys, $i, $chunkSize);
            $chunkNames = array_map(fn($k) => $categories[$k], $chunkKeys);
            $chunks[] = [
                'categories' => $chunkKeys,
                'names' => $chunkNames,
            ];
        }
        
        // Detect available vhosts for selection
        $availableVhosts = $this->detectVhosts();
        
        return [
            'success' => true,
            'data' => [
                'categories' => $categories,
                'category_details' => $categoryDetails,
                'chunks' => $chunks,
                'total' => count($categories),
                'available_vhosts' => $availableVhosts,
                'core_app_vhosts' => self::CORE_APP_VHOSTS,
                'modes' => [
                    self::MODE_FULL_CLONE => 'Full Clone - Complete server snapshot for migration',
                    self::MODE_BASE_CONFIG => 'Base Config - Generic settings for new server setup',
                ],
            ],
        ];
    }
    
    /**
     * Detect available vhosts on the server
     */
    private function detectVhosts(): array
    {
        $vhosts = [];
        $vhostDir = '/usr/local/lsws/conf/vhosts';
        
        if (!$this->dirExists($vhostDir)) {
            return $vhosts;
        }
        
        $dirs = scandir($vhostDir);
        foreach ($dirs as $dir) {
            if ($dir === '.' || $dir === '..') continue;
            
            $vhostPath = "{$vhostDir}/{$dir}";
            if (is_dir($vhostPath)) {
                // Determine if this is a core app vhost
                $isCoreApp = false;
                $coreAppKey = null;
                foreach (self::CORE_APP_VHOSTS as $key => $identifiers) {
                    foreach ($identifiers as $identifier) {
                        if (stripos($dir, $identifier) !== false) {
                            $isCoreApp = true;
                            $coreAppKey = $key;
                            break 2;
                        }
                    }
                }
                
                $vhosts[] = [
                    'name' => $dir,
                    'path' => $vhostPath,
                    'is_core_app' => $isCoreApp,
                    'core_app_key' => $coreAppKey,
                ];
            }
        }
        
        return $vhosts;
    }
    
    /**
     * Extract configs (main action)
     */
    public function actionExtract(array $params, string $actor): array
    {
        $this->log = [];
        $dryRun = $params['dry_run'] ?? false;
        $categories = $params['categories'] ?? null; // Specific categories to extract
        $mode = $params['mode'] ?? self::MODE_FULL_CLONE;
        $selectedVhosts = $params['selected_vhosts'] ?? []; // Which vhosts to include
        $includeCoreApps = $params['include_core_apps'] ?? ['panel', 'emailapp', 'fleetmanager'];
        
        $this->addLog('Starting extraction', 'info', [
            'dry_run' => $dryRun,
            'mode' => $mode,
            'categories' => $categories ? count($categories) : 'all',
            'selected_vhosts' => count($selectedVhosts),
        ]);
        
        // Get server info
        $serverInfo = $this->getServerInfo();
        $this->addLog('Server info collected', 'info', $serverInfo);
        
        // Detect installed services
        $installedServices = $this->detectInstalledServices();
        $this->addLog('Services detected', 'info', ['count' => count(array_filter($installedServices))]);
        
        // Determine which categories to extract based on mode
        $categoriesToExtract = $this->filterCategoriesByMode(
            $categories ? array_intersect_key(self::EXTRACTION_MAP, array_flip($categories)) : self::EXTRACTION_MAP,
            $mode,
            $includeCoreApps
        );
        
        $this->addLog('Categories to extract', 'info', ['count' => count($categoriesToExtract)]);
        
        // Extract configs
        $extracted = [];
        $skipped = [];
        $errors = [];
        
        foreach ($categoriesToExtract as $category => $config) {
            $this->addLog("Extracting {$config['name']} configs...", 'info');
            
            // Check if service is installed
            if (isset($config['check']) && !$this->fileExists($config['check']) && !$this->dirExists($config['check'])) {
                $skipped[$category] = ['name' => $config['name'], 'reason' => 'Not installed'];
                continue;
            }
            
            $categoryFiles = [];
            $categoryType = self::CATEGORY_TYPES[$category] ?? self::TYPE_GENERIC;
            
            // Extract individual files
            if (isset($config['files'])) {
                foreach ($config['files'] as $fileConfig) {
                    $result = $this->extractFile($fileConfig['path'], $dryRun);
                    if ($result['success']) {
                        $categoryFiles[] = $result;
                    } elseif ($fileConfig['required'] ?? false) {
                        $errors[$category][] = $result;
                    }
                }
            }
            
            // Extract directories - with vhost filtering for openlitespeed
            if (isset($config['directories'])) {
                foreach ($config['directories'] as $dir) {
                    // Special handling for vhosts directory
                    if ($category === 'openlitespeed' && strpos($dir, 'vhosts') !== false && $mode === self::MODE_BASE_CONFIG) {
                        $dirFiles = $this->extractVhostsFiltered($dir, $dryRun, $selectedVhosts, $includeCoreApps);
                    } else {
                        $dirFiles = $this->extractDirectory($dir, $dryRun);
                    }
                    $categoryFiles = array_merge($categoryFiles, $dirFiles);
                }
            }
            
            // Extract glob patterns
            if (isset($config['glob_patterns'])) {
                foreach ($config['glob_patterns'] as $pattern) {
                    $globFiles = $this->extractGlob($pattern, $dryRun);
                    $categoryFiles = array_merge($categoryFiles, $globFiles);
                }
            }
            
            // Execute commands
            if (isset($config['commands']) && !$dryRun) {
                foreach ($config['commands'] as $cmdConfig) {
                    $result = $this->extractCommand($cmdConfig['cmd'], $cmdConfig['filename']);
                    if ($result['success']) {
                        $categoryFiles[] = $result;
                    }
                }
            }
            
            if (!empty($categoryFiles)) {
                $extracted[$category] = [
                    'name' => $config['name'],
                    'type' => $categoryType,
                    'files' => $categoryFiles,
                ];
            }
        }
        
        // Build summary
        $totalFiles = 0;
        $totalSize = 0;
        $categorySummary = [];
        
        foreach ($extracted as $category => $data) {
            $catFiles = count($data['files']);
            $catSize = array_sum(array_column($data['files'], 'size'));
            $totalFiles += $catFiles;
            $totalSize += $catSize;
            $categorySummary[$category] = [
                'name' => $data['name'],
                'files' => $catFiles,
                'size' => $catSize,
            ];
        }
        
        // Reject an empty extraction: a snapshot with zero categories is useless and
        // would silently produce a broken/empty blueprint downstream.
        if (empty($extracted)) {
            return [
                'success' => false,
                'error' => 'Extraction produced no categories (nothing matched / all services missing). Refusing to create an empty snapshot.',
                'data' => [
                    'dry_run' => $dryRun,
                    'mode' => $mode,
                    'server_info' => $serverInfo,
                    'installed_services' => $installedServices,
                    'skipped' => $skipped,
                    'errors' => $errors,
                    'log' => $this->log,
                ],
            ];
        }

        return [
            'success' => true,
            'data' => [
                'dry_run' => $dryRun,
                'mode' => $mode,
                'server_info' => $serverInfo,
                'installed_services' => $installedServices,
                'extracted' => $extracted,
                'skipped' => $skipped,
                'errors' => $errors,
                'summary' => [
                    'total_files' => $totalFiles,
                    'total_size' => $totalSize,
                    'total_size_human' => $this->formatBytes($totalSize),
                    'categories_extracted' => count($extracted),
                    'categories_skipped' => count($skipped),
                    'errors_count' => count($errors),
                    'categories' => $categorySummary,
                ],
                'log' => $this->log,
            ],
        ];
    }
    
    /**
     * Filter categories based on extraction mode
     */
    private function filterCategoriesByMode(array $categories, string $mode, array $includeCoreApps): array
    {
        if ($mode === self::MODE_FULL_CLONE) {
            // In full clone mode, include everything
            return $categories;
        }
        
        // In base_config mode, filter out domain-specific categories
        $filtered = [];
        foreach ($categories as $key => $config) {
            $type = self::CATEGORY_TYPES[$key] ?? self::TYPE_GENERIC;
            
            // Skip domain-specific categories in base config mode
            if ($type === self::TYPE_DOMAIN_SPECIFIC) {
                continue;
            }
            
            // For core apps, only include if selected
            if ($type === self::TYPE_CORE_APP && !in_array($key, $includeCoreApps)) {
                continue;
            }
            
            $filtered[$key] = $config;
        }
        
        return $filtered;
    }
    
    /**
     * Extract vhosts directory with filtering for base_config mode
     */
    private function extractVhostsFiltered(string $dir, bool $dryRun, array $selectedVhosts, array $includeCoreApps): array
    {
        $files = [];
        
        if (!$this->dirExists($dir)) {
            return $files;
        }
        
        // Build list of allowed vhost directories
        $allowedVhosts = $selectedVhosts;
        
        // Add core app vhosts based on selection
        foreach ($includeCoreApps as $appKey) {
            if (isset(self::CORE_APP_VHOSTS[$appKey])) {
                $allowedVhosts = array_merge($allowedVhosts, self::CORE_APP_VHOSTS[$appKey]);
            }
        }
        
        // If no specific vhosts selected, only extract core app vhosts
        if (empty($selectedVhosts) && !empty($includeCoreApps)) {
            $this->addLog('Filtering vhosts to core apps only', 'info', ['allowed' => $allowedVhosts]);
        }
        
        $subdirs = scandir($dir);
        foreach ($subdirs as $subdir) {
            if ($subdir === '.' || $subdir === '..') continue;
            
            $vhostPath = "{$dir}/{$subdir}";
            if (!is_dir($vhostPath)) continue;
            
            // Check if this vhost should be included
            $shouldInclude = false;
            foreach ($allowedVhosts as $allowed) {
                if (stripos($subdir, $allowed) !== false) {
                    $shouldInclude = true;
                    break;
                }
            }
            
            if ($shouldInclude) {
                $this->addLog("Including vhost: {$subdir}", 'debug');
                $vhostFiles = $this->extractDirectory($vhostPath, $dryRun);
                $files = array_merge($files, $vhostFiles);
            } else {
                $this->addLog("Skipping vhost: {$subdir} (not in selection)", 'debug');
            }
        }
        
        return $files;
    }
    
    /**
     * Test extraction (simple check)
     */
    public function actionTest(array $params, string $actor): array
    {
        return [
            'success' => true,
            'data' => [
                'message' => 'Agent extraction working',
                'hostname' => trim(shell_exec('hostname') ?? ''),
                'user' => posix_getpwuid(posix_getuid())['name'] ?? 'unknown',
            ],
        ];
    }
    
    // ============================================================
    // Helper methods
    // ============================================================
    
    private function getServerInfo(): array
    {
        return [
            'hostname' => trim(shell_exec('hostname') ?? ''),
            'os' => trim(shell_exec('cat /etc/os-release | grep PRETTY_NAME | cut -d\'"\' -f2') ?? ''),
            'kernel' => trim(shell_exec('uname -r') ?? ''),
            'ip' => trim(shell_exec('hostname -I | awk \'{print $1}\'') ?? ''),
        ];
    }
    
    private function detectInstalledServices(): array
    {
        $checks = [
            'openlitespeed' => '/usr/local/lsws/bin/lshttpd',
            'php' => '/usr/local/lsws/lsphp83/bin/php',
            'mariadb' => '/usr/bin/mariadb',
            'postfix' => '/usr/sbin/postfix',
            'dovecot' => '/usr/sbin/dovecot',
            'opendkim' => '/usr/sbin/opendkim',
            'fail2ban' => '/usr/bin/fail2ban-client',
            'firewalld' => '/usr/bin/firewall-cmd',
            'openvpn' => '/usr/sbin/openvpn',
            'redis' => '/usr/bin/redis-server',
            'certbot' => '/usr/bin/certbot',
            'clamav' => '/usr/bin/clamscan',
        ];
        
        $installed = [];
        foreach ($checks as $service => $path) {
            $installed[$service] = $this->fileExists($path);
        }
        
        return $installed;
    }
    
    private function extractFile(string $path, bool $dryRun = false): array
    {
        if (!$this->fileExists($path)) {
            return ['success' => false, 'path' => $path, 'error' => 'File not found'];
        }
        
        $info = $this->getFileInfo($path);
        
        if ($dryRun) {
            return [
                'success' => true,
                'path' => $path,
                'filename' => basename($path),
                'size' => $info['size'] ?? 0,
                'permissions' => $info['permissions'] ?? '0644',
                'owner' => $info['owner'] ?? 'root',
                'group' => $info['group'] ?? 'root',
                'content' => null, // Don't read content in dry run
            ];
        }
        
        $content = $this->readFile($path);
        if ($content === null) {
            return ['success' => false, 'path' => $path, 'error' => 'Failed to read file'];
        }
        
        // Sanitize content for JSON encoding
        $content = $this->sanitizeContent($content);
        
        return [
            'success' => true,
            'path' => $path,
            'filename' => basename($path),
            'content' => $content,
            'size' => strlen($content ?? ''),
            'permissions' => $info['permissions'] ?? '0644',
            'owner' => $info['owner'] ?? 'root',
            'group' => $info['group'] ?? 'root',
        ];
    }
    
    private function extractDirectory(string $dir, bool $dryRun = false): array
    {
        $files = [];
        
        if (!$this->dirExists($dir)) {
            return $files;
        }
        
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $result = $this->extractFile($file->getPathname(), $dryRun);
                if ($result['success']) {
                    $files[] = $result;
                }
            }
        }
        
        return $files;
    }
    
    private function extractGlob(string $pattern, bool $dryRun = false): array
    {
        $files = [];
        $matches = glob($pattern);
        
        if (!$matches) {
            return $files;
        }
        
        foreach ($matches as $path) {
            if (is_file($path)) {
                $result = $this->extractFile($path, $dryRun);
                if ($result['success']) {
                    $files[] = $result;
                }
            }
        }
        
        return $files;
    }
    
    private function extractCommand(string $command, string $filename): array
    {
        $result = $this->exec($command);
        $content = $this->sanitizeContent($result['output'] ?? '');

        // Honor the command exit code, but keep any useful output produced before a
        // non-zero exit. Only drop the result when the command failed AND produced no
        // output (binary missing, permission denied, etc.) - storing that as a valid
        // extracted file would pollute the snapshot with empty/garbage entries.
        if (!($result['success'] ?? false) && trim((string) $content) === '') {
            return [
                'success' => false,
                'path' => "[command: {$command}]",
                'filename' => $filename,
                'error' => 'Command failed with no output (exit ' . ($result['exit_code'] ?? '?') . ')',
            ];
        }

        return [
            'success' => true,
            'path' => "[command: {$command}]",
            'filename' => $filename,
            'content' => $content,
            'size' => strlen($content ?? ''),
        ];
    }
    
    private function addLog(string $message, string $level = 'info', array $data = []): void
    {
        $this->log[] = [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'data' => $data,
        ];
        
        $this->logger->$level($message, $data);
    }
    
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) return '0 B';
        $k = 1024;
        $sizes = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes) / log($k));
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }
    
    /**
     * Sanitize content for JSON encoding
     * Converts invalid UTF-8 to replacement characters
     */
    private function sanitizeContent(?string $content): ?string
    {
        if ($content === null) {
            return null;
        }
        
        // Check if valid UTF-8, if not convert
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        }
        
        // Remove any remaining invalid sequences
        $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $content);
        
        return $content;
    }
}

