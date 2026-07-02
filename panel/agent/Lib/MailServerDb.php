<?php
/**
 * MailServerDb
 *
 * Access to the authoritative mail database on Docker boxes: the `mailserver`
 * database inside the containerized MariaDB (published on 127.0.0.1:3306).
 * That is the DB Dovecot/Postfix actually read there — mail accounts, domains
 * and forwards MUST be managed in it, not in the panel DB copy.
 *
 * Credentials: /root/.my.cnf (kept pointed at the container by the Fleet
 * provisioner) with the stack .env (/opt/flowone/.env) as fallback. The
 * dedicated `mailuser` is SELECT-only by design, so panel-driven writes go
 * through root. The agent runs as root, so both files are readable.
 *
 * Shared by MailAction and MailAccountAdminAction; process-cached because the
 * agent is a long-running daemon.
 */

namespace VpsAdmin\Agent\Lib;

final class MailServerDb
{
    private static ?\PDO $pdo = null;

    /** PDO to the containerized mailserver DB, or null when unavailable. */
    public static function connect(): ?\PDO
    {
        if (self::$pdo !== null) {
            try {
                self::$pdo->query('SELECT 1');
                return self::$pdo;
            } catch (\PDOException $e) {
                self::$pdo = null;
            }
        }

        $creds = self::credentials();
        if ($creds === null) {
            return null;
        }

        try {
            self::$pdo = new \PDO(
                "mysql:host={$creds['host']};port={$creds['port']};dbname={$creds['db']};charset=utf8mb4",
                $creds['user'],
                $creds['pass'],
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 5,
                    \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4',
                ]
            );
            return self::$pdo;
        } catch (\Exception $e) {
            error_log('MailServerDb: failed to connect to mailserver database: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Table qualifier for email-app tables (webmail_*, drive_quotas, ...)
     * when queries run on the mailserver DB connection: those tables live in
     * the app/panel database on the same MariaDB server, so cross-database
     * joins need the "dbname." prefix. Empty string when the panel config is
     * unavailable.
     */
    public static function appDbQualifier(): string
    {
        $configFile = '/var/www/vps-admin/api/config.php';
        $localConfigFile = '/var/www/vps-admin/api/config.local.php';
        if (!is_readable($configFile)) {
            return '';
        }
        $config = (array) require $configFile;
        if (is_readable($localConfigFile)) {
            $config = array_replace_recursive($config, (array) require $localConfigFile);
        }
        $name = (string) ($config['database']['name'] ?? '');
        return $name !== '' ? "`{$name}`." : '';
    }

    /**
     * @return array{host:string,port:int,db:string,user:string,pass:string}|null
     */
    private static function credentials(): ?array
    {
        $host = '127.0.0.1';
        $port = 3306;
        $user = 'root';
        $pass = '';

        $mycnf = '/root/.my.cnf';
        if (is_readable($mycnf)) {
            $ini = @parse_ini_file($mycnf, true, INI_SCANNER_RAW) ?: [];
            $client = $ini['client'] ?? $ini;
            $trimQuotes = static fn ($v) => trim((string) $v, "\"' ");
            if (!empty($client['password'])) {
                $pass = $trimQuotes($client['password']);
            }
            if (!empty($client['user'])) {
                $user = $trimQuotes($client['user']);
            }
            if (!empty($client['host'])) {
                $host = $trimQuotes($client['host']);
            }
            if (!empty($client['port'])) {
                $port = (int) $trimQuotes($client['port']);
            }
        }

        $db = 'mailserver';
        $envFile = '/opt/flowone/.env';
        if (is_readable($envFile)) {
            $env = (string) file_get_contents($envFile);
            if (preg_match('/^MAIL_DB_NAME=(.+)$/m', $env, $m)) {
                $db = trim($m[1]);
            }
            if ($pass === '' && preg_match('/^MYSQL_ROOT_PASSWORD=(.+)$/m', $env, $m)) {
                $pass = trim($m[1]);
            }
        }

        return $pass !== '' ? compact('host', 'port', 'db', 'user', 'pass') : null;
    }
}
