<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Services;

use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

/**
 * Read-only post-delete leftover scan.
 *
 * Runs right after a DELETE saga lands on `absent` and answers one
 * question: did the teardown actually remove everything it owns?
 * The June 2026 leftover incident (test.com / testsite.hu rows
 * lingering in mail_domains, database_links and the native PowerDNS
 * tables) went unnoticed for months precisely because nothing
 * verified delete completeness - the saga reported success and the
 * orphans only surfaced when an operator stumbled over them in the
 * Mail Security UI.
 *
 * This is the always-on, automated sibling of the legacy
 * VhostAction::actionValidateDeletion endpoint. It is deliberately
 * CHEAP: panel-DB SELECTs and filesystem stats only, no shell-outs,
 * so it can run inline at the end of every delete job without
 * meaningfully extending the saga.
 *
 * The scan NEVER mutates anything and NEVER throws - a scan hiccup
 * must not turn a successful delete into a failure. Callers audit the
 * findings; remediation stays operator-driven (fix-deletion endpoint
 * or tombstone purge).
 */
final class DeletionLeftoverScanner
{
    public function __construct(
        private readonly PanelDatabase $database
    ) {
    }

    /**
     * @return list<string> Human-readable leftover descriptors,
     *                      empty when the delete is fully clean.
     */
    public function scan(string $domain): array
    {
        if ($domain === '') {
            return [];
        }
        $leftovers = [];

        foreach ($this->dbChecks($domain) as $label => [$sql, $args]) {
            try {
                $stmt = $this->database->pdo()->prepare($sql);
                $stmt->execute($args);
                $count = (int) $stmt->fetchColumn();
                if ($count > 0) {
                    $leftovers[] = "{$label}: {$count} row(s)";
                }
            } catch (\Throwable) {
                // Table absent on this install - nothing to leak from.
            }
        }

        foreach ($this->pathChecks($domain) as $label => $path) {
            if (file_exists($path)) {
                $leftovers[] = "{$label}: {$path}";
            }
        }

        // OLS main config can hold a vhost block / listener map even
        // after the vhost dir is gone.
        $mainConf = '/usr/local/lsws/conf/httpd_config.conf';
        if (is_readable($mainConf)) {
            $content = (string) @file_get_contents($mainConf);
            if ($content !== '' && stripos($content, $domain) !== false) {
                $leftovers[] = "ols_main_config: '{$domain}' still referenced in {$mainConf}";
            }
        }

        // DKIM tables can hold lines after the key dir is removed.
        foreach (['/etc/opendkim/SigningTable', '/etc/opendkim/KeyTable'] as $table) {
            if (!is_file($table)) {
                continue;
            }
            $content = (string) @file_get_contents($table);
            $pattern = '/(^|[@.\s])' . preg_quote($domain, '/') . '([\s:]|$)/m';
            if ($content !== '' && preg_match($pattern, $content) === 1) {
                $leftovers[] = "dkim_table: '{$domain}' still listed in {$table}";
            }
        }

        return $leftovers;
    }

    /**
     * @return array<string, array{0:string, 1:list<string>}>
     */
    private function dbChecks(string $domain): array
    {
        return [
            'mail_domains' => ["SELECT COUNT(*) FROM mail_domains WHERE domain = ?", [$domain]],
            'mail_accounts' => ["SELECT COUNT(*) FROM mail_accounts WHERE domain = ?", [$domain]],
            'database_links' => ["SELECT COUNT(*) FROM database_links WHERE domain = ?", [$domain]],
            'dns_domains' => ["SELECT COUNT(*) FROM dns_domains WHERE name = ?", [$domain]],
            'pdns_domains' => ["SELECT COUNT(*) FROM domains WHERE name = ?", [$domain]],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pathChecks(string $domain): array
    {
        return [
            'home_dir' => '/home/' . $domain,
            'vmail_dir' => '/home/vmail/' . $domain,
            'ols_vhost_dir' => '/usr/local/lsws/conf/vhosts/' . $domain,
            'dkim_key_dir' => '/etc/opendkim/keys/' . $domain,
            'letsencrypt_live' => '/etc/letsencrypt/live/' . $domain,
        ];
    }
}
