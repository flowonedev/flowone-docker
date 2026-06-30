<?php

namespace VpsAdmin\Agent\Actions;

use VpsAdmin\Agent\Lib\BaseAction;

/**
 * Mail Security Gateway - Rspamd + ClamAV management (V1 foundation).
 *
 * IMPORTANT - SAFETY:
 * This action installs and configures Rspamd/ClamAV in MONITOR-ONLY mode.
 * It does NOT wire Postfix's milter to Rspamd or route live mail into the
 * quarantine transport. Those are deliberate canary steps, so installing this
 * foundation cannot affect live mail delivery.
 */
class MailSecurityAction extends BaseAction
{
    private const RSPAMD_LOCAL = '/etc/rspamd/local.d';
    private const RSPAMD_OVERRIDE = '/etc/rspamd/override.d';
    private const RSPAMD_MAPS = '/etc/rspamd/maps.d';
    private const CONTROLLER_URL = 'http://127.0.0.1:11334';

    /** MailFlow per-user Sieve resync CLI (password-less disk write path). */
    private const MAILFLOW_SIEVE_CLI = '/var/www/vps-email/backend/cron/sync-sieve-user.php';

    // MailFlow's sieve scripts live in a Maildir whose ACL grants the web-app
    // user ('nobody', LiteSpeed lsphp) read/write, and only that user can both
    // read the backend .env/DB and write the scripts (via the email-sieve
    // sudoers). The agent runs as www-data, so it hops to this user to resync.
    private const MAILFLOW_RUNAS_USER = 'nobody';

    /** Quarantine hold spool + Postfix pipe ingest (transport exists but is unwired). */
    private const QUARANTINE_SPOOL = '/var/spool/devcon-mailsec/quarantine';
    // Postfix REFUSES to run a pipe command as the mail-system owner (postfix) or
    // root ("user= command-line attribute specifies mail system owner postfix").
    // The panel web user already reads the DB config + owns the spool, so the
    // ingest runs as www-data.
    private const QUARANTINE_PIPE_USER = 'www-data';
    private const QUARANTINE_INGEST_SRC = __DIR__ . '/../scripts/quarantine-ingest.php';
    private const QUARANTINE_INGEST_DST = '/usr/local/lib/devcon-mailsec/quarantine-ingest.php';
    /** Daily retention + digest sweeper (deployed alongside the ingest script). */
    private const QUARANTINE_MAINT_SRC = __DIR__ . '/../scripts/quarantine-maintenance.php';
    private const QUARANTINE_MAINT_DST = '/usr/local/lib/devcon-mailsec/quarantine-maintenance.php';
    private const QUARANTINE_MAINT_CRON = '/etc/cron.d/devcon-mailsec-maintenance';
    /**
     * Event ingester: drains the Rspamd history into mail_security_events so the
     * dashboard/reports are real. Runs every minute; reads history + writes rows
     * only, so it can never affect mail flow.
     */
    private const EVENT_SYNC_SRC = __DIR__ . '/../scripts/mailsec-event-sync.php';
    private const EVENT_SYNC_DST = '/usr/local/lib/devcon-mailsec/mailsec-event-sync.php';
    private const EVENT_SYNC_CRON = '/etc/cron.d/devcon-mailsec-events';
    private const POSTFIX_MASTER = '/etc/postfix/master.cf';
    private const POSTFIX_MAIN = '/etc/postfix/main.cf';
    private const QUARANTINE_TRANSPORT_MARKER = '# BEGIN DEVCON Mail Security quarantine transport';

    /** Delivery wiring: Rspamd milter endpoint + quarantine routing header/map. */
    private const RSPAMD_MILTER = 'inet:localhost:11332';
    private const QUARANTINE_HEADER = 'X-Devcon-Quarantine';
    // Loaded as a regexp: table (built into Postfix). The .pcre filename is kept
    // only so existing servers self-heal in place on re-wire; contents are POSIX ERE.
    private const QUARANTINE_HEADER_CHECKS = '/etc/postfix/devcon_mailsec_header_checks.pcre';

    /**
     * Local recursive resolver for Rspamd DNSBLs. Bound to 127.0.0.1:5335 so it
     * never collides with a :53 DNS server already on the host (PowerDNS,
     * systemd-resolved, etc.). Public resolvers (8.8.8.8/1.1.1.1) are blocked by
     * most DNSBL providers, which silently disables RBL scanning.
     */
    private const UNBOUND_CONF_D = '/etc/unbound/unbound.conf.d';
    private const UNBOUND_RESOLVER_CONF = '/etc/unbound/unbound.conf.d/devcon-rspamd-resolver.conf';
    private const RESOLVER_PORT = 5335;

    /**
     * Fixed allowlist of map files this action manages. Names are server-controlled
     * (never user input) so they cannot be used for path traversal.
     */
    private const MANAGED_MAPS = [
        'mailsec_whitelist_email',
        'mailsec_whitelist_domain',
        'mailsec_whitelist_ip',
        'mailsec_blacklist_email',
        'mailsec_blacklist_domain',
        'mailsec_blacklist_ip',
    ];

    /** Anti-spoofing / CEO-fraud (BEC) maps + the Lua rule that consumes them. */
    private const IMP_MAP_VIP = 'mailsec_vip_names';
    private const IMP_MAP_DOMAINS = 'mailsec_protected_domains';
    private const IMP_MAP_EXEMPT = 'mailsec_exempt_senders';
    /** Live-reloaded lookalike config (enabled + sensitivity); a watched map. */
    private const LOOKALIKE_MAP = 'mailsec_lookalike';
    private const LUA_LOCAL = '/etc/rspamd/rspamd.local.lua';
    private const IMP_LUA_BEGIN = '-- BEGIN DEVCON Mail Security impersonation';
    private const IMP_LUA_END = '-- END DEVCON Mail Security impersonation';

    /** Mail flow rules engine: watched JSON map + the Lua postfilter that runs it. */
    private const RULES_MAP = 'mailsec_rules';
    private const RULES_LUA_BEGIN = '-- BEGIN DEVCON Mail Security rules engine';
    private const RULES_LUA_END = '-- END DEVCON Mail Security rules engine';

    /** Geo-IP country filtering: watched JSON map + the Lua postfilter that runs it. */
    private const GEOIP_MAP = 'mailsec_geoip';
    private const GEOIP_LUA_BEGIN = '-- BEGIN DEVCON Mail Security geoip';
    private const GEOIP_LUA_END = '-- END DEVCON Mail Security geoip';

    /** Legacy single regexp map of banned attachment patterns (now split by action). */
    private const ATTACHMENT_MAP = 'mailsec_bad_extensions';
    /** Banned attachment patterns whose policy is "reject at SMTP". */
    private const ATTACHMENT_MAP_REJECT = 'mailsec_bad_ext_reject';
    /** Banned attachment patterns whose policy is "hold for admin review". */
    private const ATTACHMENT_MAP_QUARANTINE = 'mailsec_bad_ext_quarantine';

    /**
     * Reactive learning loop: Dovecot IMAPSieve hooks that train Rspamd's Bayes
     * classifier whenever a user drags a message into / out of Junk in ANY mail
     * client (Outlook, Apple Mail, Thunderbird, etc.). Without these hooks only
     * webmail "Report Spam" buttons train the engine, which leaves IMAP users
     * silently un-trained.
     */
    private const LEARN_WRAPPER_SRC = __DIR__ . '/../scripts/mailsec-learn-wrapper.php';
    /** Dovecot sieve_extprograms refuses to run any binary outside its allow-listed dir. */
    private const LEARN_PIPE_DIR = '/usr/local/lib/devcon-mailsec/sieve-pipe';
    private const LEARN_PIPE_BIN = '/usr/local/lib/devcon-mailsec/sieve-pipe/devcon-mailsec-learn';
    /** Spool drained by the per-minute event-sync ingester into mail_security_learn_events. */
    private const LEARN_SPOOL_DIR = '/var/spool/devcon-mailsec/learn-events';
    /** Per-user opt-out list re-synced from webmail_spam_settings every minute by the ingester. */
    private const LEARN_OPTOUT_FILE = '/etc/devcon-mailsec/learn-optouts.txt';
    /** Compiled IMAPSieve scripts that pipe the message to the learn wrapper. */
    private const LEARN_SIEVE_DIR = '/var/lib/dovecot/sieve';
    private const LEARN_SIEVE_SPAM = '/var/lib/dovecot/sieve/devcon-learn-spam.sieve';
    private const LEARN_SIEVE_HAM = '/var/lib/dovecot/sieve/devcon-learn-ham.sieve';
    /** Standalone Dovecot conf so it doesn't tangle with the existing Junk-filing one. */
    private const LEARN_DOVECOT_CONF = '/etc/dovecot/conf.d/99-devcon-mailsec-learn.conf';
    /** Main Dovecot config - some installs are monolithic and never glob conf.d, so we add an explicit !include here when our conf isn't being loaded. */
    private const LEARN_DOVECOT_MAIN = '/etc/dovecot/dovecot.conf';
    /** Distinctive string from our conf used to detect (via doveconf -n) whether Dovecot actually loaded it. */
    private const LEARN_LOAD_MARKER = 'devcon-mailsec/sieve-pipe';

    public function getNamespace(): string
    {
        return 'mailsec';
    }

    public function getMethods(): array
    {
        return [
            'status',
            'install',
            'start',
            'stop',
            'restart',
            'getConfig',
            'saveConfig',
            'getScores',
            'setScores',
            'getStats',
            'clamavStatus',
            'updateSignatures',
            'restartClamav',
            'exportMaps',
            'syncUserSieve',
            'authStatus',
            'setupQuarantine',
            'releaseQuarantine',
            'deleteQuarantineFile',
            'maintainQuarantine',
            'syncEvents',
            'wireMilter',
            'unwireMilter',
            'deliveryStatus',
            'tailLog',
            'setupResolver',
            'setupLearning',
            'learnStatus',
            'setBayesAutolearn',
        ];
    }

    public function requiresBackup(string $method): bool
    {
        return in_array($method, ['install', 'saveConfig', 'setScores', 'setupQuarantine', 'wireMilter', 'unwireMilter', 'setupLearning', 'setBayesAutolearn'], true);
    }

    // ==================== STATUS ====================

    /**
     * Report engine status. Read-only; safe to call any time.
     */
    protected function actionStatus(array $params, string $actor): array
    {
        $rspamdInstalled = $this->commandExists('rspamadm') || is_dir('/etc/rspamd');
        $clamInstalled = $this->commandExists('clamdscan') || is_dir('/etc/clamav');

        $scores = $this->readScores();

        return $this->success([
            'rspamd' => [
                'installed' => $rspamdInstalled,
                'running' => $this->serviceActive('rspamd'),
                'version' => $this->rspamdVersion(),
            ],
            'clamav' => [
                'installed' => $clamInstalled,
                'daemon_running' => $this->serviceActive('clamav-daemon'),
                'freshclam_running' => $this->serviceActive('clamav-freshclam'),
            ],
            'scores' => $scores,
            // milter_wired reflects whether Postfix is actually pointed at Rspamd.
            // While false, the engine is monitor-only and cannot affect delivery.
            'milter_wired' => $this->isMilterWired(),
            'mode' => $this->isMilterWired() ? 'active' : 'monitor',
            'quarantine' => $this->quarantineStatus(),
            'delivery' => $this->deliveryState(),
            // Threat protection modules (file presence = wired during install).
            'protection' => [
                'phishing' => file_exists(self::RSPAMD_LOCAL . '/phishing.conf'),
                'reputation' => file_exists(self::RSPAMD_LOCAL . '/reputation.conf'),
                'impersonation' => file_exists(self::LUA_LOCAL)
                    && str_contains((string) @file_get_contents(self::LUA_LOCAL), self::IMP_LUA_BEGIN),
            ],
        ]);
    }

    // ==================== INSTALL (monitor-only) ====================

    /**
     * Install Rspamd + ClamAV and write a monitor-only baseline config.
     * Never touches Postfix. Heavy action (forked by the agent).
     */
    protected function actionInstall(array $params, string $actor): array
    {
        if (!file_exists('/usr/bin/apt-get')) {
            return $this->error('Unsupported platform: apt-get not found (Debian/Ubuntu required).');
        }

        $steps = [];

        // 1. Install packages (redis is required by Rspamd for bayes/stats).
        $update = $this->execCommand('env', ['DEBIAN_FRONTEND=noninteractive', 'apt-get', 'update'], 180);
        $steps['apt_update'] = $update['success'];

        $install = $this->execCommand('env', [
            'DEBIAN_FRONTEND=noninteractive', 'apt-get', 'install', '-y',
            'rspamd', 'clamav', 'clamav-daemon', 'clamav-freshclam', 'redis-server',
        ], 600);
        $steps['apt_install'] = $install['success'];

        if (!$install['success']) {
            return $this->error('Package installation failed: ' . $install['output'], ['steps' => $steps]);
        }

        // 2. Write the monitor-only baseline config (each write is backed up).
        $this->ensureDir(self::RSPAMD_LOCAL);

        $spam = (float)($params['spam_score'] ?? 6);
        $reject = (float)($params['reject_score'] ?? 15);

        $steps['actions_conf'] = $this->writeConfig(
            self::RSPAMD_LOCAL . '/actions.conf',
            $this->renderActionsConf($spam, $reject),
            $actor
        );

        // Antivirus module - fail OPEN (only adds a header on AV failure/hit,
        // never rejects) so a ClamAV outage can never block mail.
        $steps['antivirus_conf'] = $this->writeConfig(
            self::RSPAMD_LOCAL . '/antivirus.conf',
            $this->renderAntivirusConf(),
            $actor
        );

        // Controller bound to localhost so the panel can read /stat without a password.
        $steps['worker_controller'] = $this->writeConfig(
            self::RSPAMD_LOCAL . '/worker-controller.inc',
            "bind_socket = \"localhost:11334\";\nsecure_ip = \"127.0.0.1\";\nsecure_ip = \"::1\";\n",
            $actor
        );

        // Point all Redis-backed modules (Bayes statistics, fuzzy, ratelimit) at
        // the local redis we installed/started below. Without a configured
        // backend, Rspamd's Bayes reports "classifier: (null)" and learning fails
        // - which would silently break per-user spam training (rspamc learn_*).
        $steps['redis_conf'] = $this->writeConfig(
            self::RSPAMD_LOCAL . '/redis.conf',
            "servers = \"127.0.0.1:6379\";\n",
            $actor
        );

        // Bayes classifier on Redis with threshold autolearn enabled.
        $steps['bayes_conf'] = $this->writeConfig(
            self::RSPAMD_LOCAL . '/classifier-bayes.conf',
            "backend = \"redis\";\nautolearn = true;\n",
            $actor
        );

        // Keep a generous Redis-backed scan history so the per-minute event
        // ingester (mailsec-event-sync) never loses rows between polls. This is
        // storage-only and cannot affect mail flow.
        $steps['history_conf'] = $this->writeConfig(
            self::RSPAMD_LOCAL . '/history_redis.conf',
            "nrows = 5000;\n",
            $actor
        );

        // Phishing detection (heuristics + OpenPhish/Phishtank feeds) and
        // Redis-backed sender reputation (IP/SPF/DKIM). Validated with
        // rspamadm configtest and rolled back on failure, so a bad module
        // config can never take down the live engine.
        $protection = $this->setupPhishingReputation($actor);
        $steps['phishing_reputation'] = $protection['success'];

        // Anti-spoofing / CEO-fraud Lua rule (reads the impersonation maps).
        // Same configtest-gate + rollback discipline as above.
        $impersonation = $this->ensureImpersonationLua($actor);
        $steps['impersonation'] = $impersonation['success'];

        // Mail flow rules engine: the static Lua postfilter that interprets the
        // watched rules map. configtest-gated + rolled back on failure.
        $rulesLua = $this->ensureRulesLua($actor);
        $steps['rules_engine'] = $rulesLua['success'];

        // Geo-IP country filtering: ensure the ASN module resolves country, then
        // install the watched-map Lua postfilter. configtest-gated + rolled back.
        $steps['geoip_asn'] = $this->ensureAsnModule($actor)['success'] ?? false;
        $geoipLua = $this->ensureGeoipLua($actor);
        $steps['geoip_engine'] = $geoipLua['success'];

        // Global Junk-filing Sieve so 'move' rules (X-Spam-Flag: YES) land in Junk.
        $steps['junk_sieve'] = $this->ensureJunkSieve($actor)['success'] ?? false;

        // Reactive learning loop: IMAPSieve hooks so any client's Junk drag
        // trains Rspamd's Bayes classifier. Best-effort; rolled back on failure
        // so a broken Dovecot config can never break the install.
        $learn = $this->ensureLearningLoop($actor, true);
        $steps['learning_loop'] = $learn['success'] ?? false;

        // 3. Enable + (re)start services. Do not hard-fail if ClamAV is still
        // downloading signatures - that is expected on first install.
        $this->execCommand('systemctl', ['enable', 'redis-server'], 30);
        $this->execCommand('systemctl', ['restart', 'redis-server'], 30);
        $this->execCommand('systemctl', ['enable', 'clamav-freshclam'], 30);
        $this->execCommand('systemctl', ['restart', 'clamav-freshclam'], 60);
        $this->execCommand('systemctl', ['enable', 'clamav-daemon'], 30);
        $this->execCommand('systemctl', ['restart', 'clamav-daemon'], 60);
        $this->execCommand('systemctl', ['enable', 'rspamd'], 30);
        $restart = $this->execCommand('systemctl', ['restart', 'rspamd'], 60);
        $steps['rspamd_started'] = $restart['success'];

        // 4. Quarantine spool + Postfix pipe transport (defined but not routed).
        $qSetup = $this->setupQuarantineInternal($actor);
        $steps['quarantine'] = $qSetup['success'] ?? false;

        // 4b. Event ingester script + per-minute cron (history -> events table).
        $steps['event_sync'] = $this->ensureEventSync($actor);

        // 5. Local recursive resolver for reliable DNSBL lookups. Best-effort and
        //    fail-safe: if it cannot come up, Rspamd is left on the system resolver.
        $resolver = $this->setupResolverInternal($actor);
        $steps['resolver'] = $resolver;

        return $this->success([
            'steps' => $steps,
            'status' => $this->actionStatus([], $actor)['data'] ?? null,
            'note' => 'Installed in MONITOR-ONLY mode. Quarantine transport is ready but unwired; milter is not connected.',
        ], "Mail Security engine installed (monitor-only) by {$actor}");
    }

    // ==================== SERVICE CONTROL ====================

    protected function actionStart(array $params, string $actor): array
    {
        return $this->serviceControl('start', $actor);
    }

    protected function actionStop(array $params, string $actor): array
    {
        return $this->serviceControl('stop', $actor);
    }

    protected function actionRestart(array $params, string $actor): array
    {
        return $this->serviceControl('restart', $actor);
    }

    private function serviceControl(string $verb, string $actor): array
    {
        $result = $this->execCommand('systemctl', [$verb, 'rspamd'], 60);
        if (!$result['success']) {
            return $this->error("Failed to {$verb} Rspamd: " . $result['output']);
        }
        return $this->success([
            'running' => $this->serviceActive('rspamd'),
        ], "Rspamd {$verb} by {$actor}");
    }

    // ==================== CONFIG VIEWER / EDITOR ====================

    protected function actionGetConfig(array $params, string $actor): array
    {
        $file = $params['file'] ?? (self::RSPAMD_LOCAL . '/actions.conf');
        if (!$this->validateConfigPath($file)) {
            return $this->error('Invalid config file path. Must be within /etc/rspamd/local.d or /etc/rspamd/override.d.');
        }

        return $this->success([
            'path' => $file,
            'content' => file_exists($file) ? file_get_contents($file) : '',
            'exists' => file_exists($file),
        ]);
    }

    protected function actionSaveConfig(array $params, string $actor): array
    {
        $file = $params['file'] ?? '';
        if (!$this->validateConfigPath($file)) {
            return $this->error('Invalid config file path. Must be within /etc/rspamd/local.d or /etc/rspamd/override.d.');
        }
        if (!isset($params['content'])) {
            return $this->error('Content is required');
        }

        $content = str_replace("\r\n", "\n", (string)$params['content']);
        $ok = $this->writeConfig($file, $content, $actor);
        if (!$ok) {
            return $this->error('Failed to write config file');
        }

        // Validate config before reloading so a bad edit cannot break Rspamd.
        $check = $this->execCommand('rspamadm', ['configtest'], 30);
        if (!$check['success']) {
            return $this->error('Config rejected by rspamadm configtest (not reloaded): ' . $check['output']);
        }

        $this->execCommand('systemctl', ['reload', 'rspamd'], 30);

        return $this->success([
            'path' => $file,
        ], "Rspamd config {$file} saved by {$actor}");
    }

    // ==================== SCORE THRESHOLDS ====================

    protected function actionGetScores(array $params, string $actor): array
    {
        return $this->success(['scores' => $this->readScores()]);
    }

    protected function actionSetScores(array $params, string $actor): array
    {
        $spam = isset($params['spam_score']) ? (float)$params['spam_score'] : 6.0;
        $reject = isset($params['reject_score']) ? (float)$params['reject_score'] : 15.0;

        if ($reject <= $spam) {
            return $this->error('Reject score must be greater than the spam score.');
        }

        $ok = $this->writeConfig(
            self::RSPAMD_LOCAL . '/actions.conf',
            $this->renderActionsConf($spam, $reject),
            $actor
        );
        if (!$ok) {
            return $this->error('Failed to write actions.conf');
        }

        $this->execCommand('systemctl', ['reload', 'rspamd'], 30);

        return $this->success([
            'scores' => $this->readScores(),
        ], "Spam scores updated by {$actor}");
    }

    // ==================== STATS ====================

    protected function actionGetStats(array $params, string $actor): array
    {
        $raw = @file_get_contents(self::CONTROLLER_URL . '/stat', false, stream_context_create([
            'http' => ['timeout' => 5],
        ]));

        if ($raw === false) {
            return $this->success(['available' => false, 'stat' => null]);
        }

        return $this->success([
            'available' => true,
            'stat' => json_decode($raw, true),
        ]);
    }

    // ==================== ANTIVIRUS (ClamAV management) ====================

    /**
     * Full ClamAV status: daemon/freshclam health, engine + signature DB
     * version/date/count, last DB update, whether Rspamd's antivirus module is
     * wired, and recent antivirus detections from the Rspamd history. Read-only.
     */
    protected function actionClamavStatus(array $params, string $actor): array
    {
        $info = [
            'installed' => $this->commandExists('clamdscan') || is_dir('/etc/clamav'),
            'daemon_running' => $this->serviceActive('clamav-daemon'),
            'freshclam_running' => $this->serviceActive('clamav-freshclam'),
            'engine_version' => null,
            'db_version' => null,
            'db_date' => null,
            'signatures' => null,
            'db_updated' => null,
            'antivirus_module' => file_exists(self::RSPAMD_LOCAL . '/antivirus.conf'),
            'recent_detections' => $this->countAntivirusHistory(),
        ];

        // Engine + DB version. clamdscan --version queries the running daemon and
        // returns e.g. "ClamAV 0.103.11/27123/Fri Jun  5 09:00:00 2026".
        if ($this->commandExists('clamdscan')) {
            $ver = $this->execCommand('clamdscan', ['--version'], 15);
            $vstr = trim($ver['output'] ?? '');
            if ($vstr !== '' && stripos($vstr, 'ClamAV') !== false) {
                $parts = explode('/', $vstr);
                $info['engine_version'] = trim($parts[0]);
                if (isset($parts[1])) {
                    $info['db_version'] = trim($parts[1]);
                }
                if (isset($parts[2])) {
                    $info['db_date'] = trim($parts[2]);
                }
            }
        }

        // DB file mtime (prefer the incremental .cld, fall back to .cvd).
        foreach (['/var/lib/clamav/daily.cld', '/var/lib/clamav/daily.cvd'] as $dbf) {
            if (is_file($dbf)) {
                $info['db_updated'] = date('c', (int)filemtime($dbf));
                break;
            }
        }

        // Total signatures across main + daily + bytecode (best-effort via sigtool).
        if ($this->commandExists('sigtool')) {
            $total = 0;
            $any = false;
            foreach (['main', 'daily', 'bytecode'] as $base) {
                foreach (["{$base}.cld", "{$base}.cvd"] as $f) {
                    $path = "/var/lib/clamav/{$f}";
                    if (!is_file($path)) {
                        continue;
                    }
                    $si = $this->execCommand('sigtool', ['--info', $path], 20);
                    if (preg_match('/Signatures:\s*(\d+)/i', $si['output'] ?? '', $m)) {
                        $total += (int)$m[1];
                        $any = true;
                    }
                    break; // one file per base is enough
                }
            }
            if ($any) {
                $info['signatures'] = $total;
            }
        }

        return $this->success(['clamav' => $info]);
    }

    /**
     * Count recent antivirus detections from the Rspamd controller history.
     * Live, best-effort; returns null if history is unavailable.
     */
    private function countAntivirusHistory(): ?int
    {
        $raw = @file_get_contents(self::CONTROLLER_URL . '/history', false, stream_context_create([
            'http' => ['timeout' => 5],
        ]));
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        $rows = $data['rows'] ?? (is_array($data) ? $data : null);
        if (!is_array($rows)) {
            return null;
        }
        $count = 0;
        foreach ($rows as $row) {
            $symbols = $row['symbols'] ?? [];
            if (!is_array($symbols)) {
                continue;
            }
            foreach ($symbols as $key => $val) {
                $name = strtoupper(is_string($key) ? $key : (string)($val['name'] ?? ''));
                // Genuine antivirus only: 'CLAM' anywhere, but 'VIRUS' only as a
                // whole token so reputation symbols like RBL_VIRUSFREE_BOTNET are
                // not counted as malware.
                if (strpos($name, 'CLAM') !== false || preg_match('/(^|_)VIRUS(_|$)/', $name)) {
                    $count++;
                    break;
                }
            }
        }
        return $count;
    }

    /**
     * Force a ClamAV signature update via freshclam. The one-shot run conflicts
     * with the freshclam daemon's lock, so we stop it briefly and restart it.
     */
    protected function actionUpdateSignatures(array $params, string $actor): array
    {
        if (!$this->commandExists('freshclam')) {
            return $this->error('freshclam is not installed');
        }

        $wasRunning = $this->serviceActive('clamav-freshclam');
        if ($wasRunning) {
            $this->execCommand('systemctl', ['stop', 'clamav-freshclam'], 30);
        }
        $run = $this->execCommand('freshclam', ['--stdout'], 300);
        if ($wasRunning) {
            $this->execCommand('systemctl', ['start', 'clamav-freshclam'], 30);
        }

        $out = trim($run['output'] ?? '');
        $ok = $run['success']
            || stripos($out, 'up-to-date') !== false
            || stripos($out, 'up to date') !== false
            || stripos($out, 'updated') !== false;
        if (!$ok) {
            return $this->error('Signature update failed: ' . $out, ['output' => $out]);
        }

        return $this->success([
            'output' => $out,
            'clamav' => $this->actionClamavStatus([], $actor)['data']['clamav'] ?? null,
        ], "ClamAV signatures updated by {$actor}");
    }

    /**
     * Restart the ClamAV daemon (and freshclam). Used to recover a wedged scanner.
     */
    protected function actionRestartClamav(array $params, string $actor): array
    {
        $daemon = $this->execCommand('systemctl', ['restart', 'clamav-daemon'], 60);
        $this->execCommand('systemctl', ['restart', 'clamav-freshclam'], 30);
        if (!$daemon['success']) {
            return $this->error('Failed to restart clamav-daemon: ' . trim($daemon['output'] ?? ''));
        }
        return $this->success([
            'clamav' => $this->actionClamavStatus([], $actor)['data']['clamav'] ?? null,
        ], "ClamAV restarted by {$actor}");
    }

    // ==================== MAP EXPORT (lists -> Rspamd multimap) ====================

    /**
     * Write the global allow/block lists to Rspamd multimap files and ensure the
     * multimap config references them, then reload Rspamd.
     *
     * SAFETY: This only touches Rspamd's own map/config files. Postfix is not
     * modified, so while the milter is unwired these maps have no delivery effect.
     *
     * Expected params['maps'] = [ '<managed map name>' => [list of values], ... ]
     */
    protected function actionExportMaps(array $params, string $actor): array
    {
        $maps = $params['maps'] ?? [];
        if (!is_array($maps)) {
            return $this->error('maps must be an object of name => values');
        }

        $this->ensureDir(self::RSPAMD_MAPS);
        $written = [];

        foreach (self::MANAGED_MAPS as $name) {
            $values = isset($maps[$name]) && is_array($maps[$name]) ? $maps[$name] : [];
            $path = self::RSPAMD_MAPS . '/' . $name . '.map';

            // Sanitise: one entry per line, drop blanks and anything with whitespace.
            $clean = [];
            foreach ($values as $v) {
                $v = trim((string)$v);
                if ($v !== '' && !preg_match('/\s/', $v)) {
                    $clean[] = strtolower($v);
                }
            }
            $clean = array_values(array_unique($clean));

            $content = "# Managed by DEVCON Mail Security. Do not edit by hand.\n"
                . implode("\n", $clean) . (empty($clean) ? '' : "\n");

            if (file_exists($path)) {
                $this->backupFile($path, 'exportMaps', $actor);
            }
            if (file_put_contents($path, $content) === false) {
                return $this->error("Failed to write map file: {$name}");
            }
            @chmod($path, 0644);
            $written[$name] = count($clean);
        }

        // Anti-spoofing / CEO-fraud maps (VIP display names, protected domains,
        // exempt senders). The Lua rule that consumes them is deployed at install.
        $this->writeImpersonationMaps($params['impersonation'] ?? [], $actor, $written);

        // Mail flow rules (JSON: enforcement mode + ordered rule list). Consumed
        // live by the Lua postfilter via a watched map; deployed at install.
        $this->writeRulesMap($params['rules'] ?? [], $actor, $written);

        // Geo-IP policy (JSON: enforcement mode + global default + per-domain
        // overrides). Consumed live by the Lua postfilter via a watched map.
        $this->writeGeoipMap($params['geoip'] ?? [], $actor, $written);

        // Banned attachment extensions, grouped by the admin-chosen per-extension
        // action. Accepts the grouped shape { reject:[...], quarantine:[...] } or a
        // flat legacy list (then governed by the single 'attachment_action' param).
        $badExt = $params['bad_extensions'] ?? [];
        if (isset($badExt['reject']) || isset($badExt['quarantine'])) {
            $rejectExt = $this->cleanExtensions($badExt['reject'] ?? []);
            $quarantineExt = $this->cleanExtensions($badExt['quarantine'] ?? []);
        } else {
            $flat = $this->cleanExtensions($badExt);
            $globalAction = (($params['attachment_action'] ?? 'quarantine') === 'reject') ? 'reject' : 'quarantine';
            $rejectExt = $globalAction === 'reject' ? $flat : [];
            $quarantineExt = $globalAction === 'reject' ? [] : $flat;
        }
        // Reject wins if the same extension somehow lands in both buckets.
        $quarantineExt = array_values(array_diff($quarantineExt, $rejectExt));

        $this->writeExtensionMap(self::ATTACHMENT_MAP_REJECT, $rejectExt, $actor, $written);
        $this->writeExtensionMap(self::ATTACHMENT_MAP_QUARANTINE, $quarantineExt, $actor, $written);
        // Retire the legacy single map now that policy is split by action.
        @unlink(self::RSPAMD_MAPS . '/' . self::ATTACHMENT_MAP . '.map');

        $hasReject = !empty($rejectExt);
        $hasQuarantine = !empty($quarantineExt);

        // multimap.conf binds the two maps to scored symbols.
        $this->ensureDir(self::RSPAMD_LOCAL);
        $confPath = self::RSPAMD_LOCAL . '/multimap.conf';
        if (file_exists($confPath)) {
            $this->backupFile($confPath, 'exportMaps', $actor);
        }
        file_put_contents($confPath, $this->renderMultimapConf($hasReject, $hasQuarantine));
        @chmod($confPath, 0644);

        // force_actions.conf pins each group's outcome deterministically.
        $faPath = self::RSPAMD_LOCAL . '/force_actions.conf';
        if (file_exists($faPath)) {
            $this->backupFile($faPath, 'exportMaps', $actor);
        }
        file_put_contents($faPath, $this->renderForceActionsConf($hasReject, $hasQuarantine));
        @chmod($faPath, 0644);

        // Validate before reloading so a bad map config can never break Rspamd.
        $check = $this->execCommand('rspamadm', ['configtest'], 30);
        if (!$check['success']) {
            return $this->error('Rspamd configtest failed (not reloaded): ' . $check['output'], ['written' => $written]);
        }
        $this->execCommand('systemctl', ['reload', 'rspamd'], 30);

        return $this->success([
            'written' => $written,
            'attachment_policy' => ['reject' => count($rejectExt), 'quarantine' => count($quarantineExt)],
            'milter_wired' => $this->isMilterWired(),
        ], "Exported global lists to Rspamd maps by {$actor}");
    }

    /** Normalise a list of file extensions: lowercased, dot-stripped, validated, unique. */
    private function cleanExtensions($arr): array
    {
        $out = [];
        if (is_array($arr)) {
            foreach ($arr as $e) {
                $e = ltrim(strtolower(trim((string)$e)), '.');
                if ($e !== '' && preg_match('/^[a-z0-9]{1,16}$/', $e)) {
                    $out[] = $e;
                }
            }
        }
        return array_values(array_unique($out));
    }

    /** Write one regexp filename map (one pattern per extension) and record its count. */
    private function writeExtensionMap(string $mapName, array $exts, string $actor, array &$written): void
    {
        $path = self::RSPAMD_MAPS . '/' . $mapName . '.map';
        $lines = array_map(static fn($e) => '/\\.' . $e . '$/i', $exts);
        $content = "# Managed by DEVCON Mail Security. Do not edit by hand.\n"
            . implode("\n", $lines) . (empty($lines) ? '' : "\n");
        if (file_exists($path)) {
            $this->backupFile($path, 'exportMaps', $actor);
        }
        file_put_contents($path, $content);
        @chmod($path, 0644);
        $written[$mapName] = count($exts);
    }

    private function renderMultimapConf(bool $hasRejectAttach = false, bool $hasQuarantineAttach = false): string
    {
        $maps = self::RSPAMD_MAPS;
        $rejectMap = self::ATTACHMENT_MAP_REJECT;
        $quarMap = self::ATTACHMENT_MAP_QUARANTINE;
        // The deterministic outcome is pinned by the force_actions rules (see
        // renderForceActionsConf). These symbol scores are a sensible fallback if
        // that module is unavailable: reject lands on its own above the default
        // reject threshold; quarantine lands in the add-header band.
        $attachmentRule = '';
        if ($hasRejectAttach) {
            $attachmentRule .= <<<ATT
MAILSEC_ATTACH_REJECT {
  type = "filename";
  map = "regexp;{$maps}/{$rejectMap}.map";
  symbol = "MAILSEC_ATTACH_REJECT";
  score = 20.0;
  description = "DEVCON banned attachment (reject policy)";
}

ATT;
        }
        if ($hasQuarantineAttach) {
            $attachmentRule .= <<<ATT
MAILSEC_ATTACH_QUARANTINE {
  type = "filename";
  map = "regexp;{$maps}/{$quarMap}.map";
  symbol = "MAILSEC_ATTACH_QUARANTINE";
  score = 8.0;
  description = "DEVCON banned attachment (quarantine policy)";
}

ATT;
        }
        // Whitelist symbols carry a strong negative score; blacklist a strong
        // positive score. Final enforcement still depends on the action thresholds
        // and (crucially) on Postfix being wired to the milter.
        return <<<CONF
# Managed by DEVCON Mail Security. Do not edit by hand.
MAILSEC_WL_EMAIL {
  type = "from";
  map = "{$maps}/mailsec_whitelist_email.map";
  score = -15.0;
  description = "DEVCON global allow list (email)";
}
MAILSEC_WL_DOMAIN {
  type = "from";
  filter = "email:domain";
  map = "{$maps}/mailsec_whitelist_domain.map";
  score = -12.0;
  description = "DEVCON global allow list (domain)";
}
MAILSEC_WL_IP {
  type = "ip";
  map = "{$maps}/mailsec_whitelist_ip.map";
  score = -12.0;
  description = "DEVCON global allow list (ip/cidr)";
}
MAILSEC_BL_EMAIL {
  type = "from";
  map = "{$maps}/mailsec_blacklist_email.map";
  score = 15.0;
  description = "DEVCON global block list (email)";
}
MAILSEC_BL_DOMAIN {
  type = "from";
  filter = "email:domain";
  map = "{$maps}/mailsec_blacklist_domain.map";
  score = 12.0;
  description = "DEVCON global block list (domain)";
}
MAILSEC_BL_IP {
  type = "ip";
  map = "{$maps}/mailsec_blacklist_ip.map";
  score = 12.0;
  description = "DEVCON global block list (ip/cidr)";
}

{$attachmentRule}
CONF;
    }

    /**
     * Deterministic enforcement for banned attachments via Rspamd's
     * force_actions module. Score arithmetic alone can't do this cleanly:
     * Rspamd already scores some extensions (e.g. .exe) highly, which would
     * force a reject regardless of our intent, while custom extensions it does
     * not flag could slip below the spam band. So we pin the action by symbol:
     *   - 'reject'     -> reject at SMTP.
     *   - 'quarantine' -> force the "add header" action so Rspamd stamps
     *                     X-Devcon-Quarantine (Postfix then holds it), UNLESS
     *                     ClamAV flagged a virus, which still rejects.
     * Returns an empty ruleset when there are no banned extensions configured.
     */
    private function renderForceActionsConf(bool $hasReject = false, bool $hasQuarantine = false): string
    {
        if (!$hasReject && !$hasQuarantine) {
            return "# Managed by DEVCON Mail Security. No banned attachment policy configured.\nrules {\n}\n";
        }

        $rules = '';
        if ($hasReject) {
            $rules .= <<<RULE
  devcon_attach_reject {
    action = "reject";
    expression = "MAILSEC_ATTACH_REJECT";
    message = "Message rejected: contains a prohibited attachment type";
  }

RULE;
        }
        if ($hasQuarantine) {
            // Hold for admin review, but never hold a known virus (still rejects).
            $rules .= <<<RULE
  devcon_attach_quarantine {
    action = "add header";
    expression = "MAILSEC_ATTACH_QUARANTINE & !CLAM_VIRUS";
    message = "Held for review: prohibited attachment type";
  }

RULE;
        }

        return "# Managed by DEVCON Mail Security. Do not edit by hand.\nrules {\n{$rules}}\n";
    }

    // ==================== PER-USER SIEVE RESYNC (MailFlow) ====================

    /**
     * Resolve a PHP CLI binary new enough to run the MailFlow backend. Its
     * vendor/ enforces PHP >= 8.2 via Composer's platform check, so on hosts
     * where the default `php` is older (e.g. a panel pinned to 8.1 while the
     * mail stack runs 8.2+) bare `php` aborts at autoload. Prefer the newest
     * explicit php8.x on PATH, else fall back to `php`.
     */
    private function resolvePhpBinary(): string
    {
        // The MailFlow backend needs PHP >= 8.2 AND the pdo_mysql driver (its DB
        // layer aborts without it, which would otherwise yield an empty Sieve).
        // Prefer the newest versioned php8.x that actually loads pdo_mysql; only
        // then fall back to bare `php`.
        foreach (['php8.4', 'php8.3', 'php8.2'] as $bin) {
            if (trim((string)@shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null')) === '') {
                continue;
            }
            $hasPdo = (int) trim((string)@shell_exec(
                escapeshellarg($bin) . " -m 2>/dev/null | grep -c '^pdo_mysql\$'"
            ));
            if ($hasPdo > 0) {
                return $bin;
            }
        }
        return 'php';
    }

    /**
     * Regenerate one user's MailFlow Sieve script so an admin's per-user
     * allow/block edit (made from the Panel) takes effect at delivery.
     *
     * Delegates to MailFlow's own CLI, which owns the Sieve logic. We only
     * pass a validated email address. No Postfix/Rspamd changes here.
     */
    protected function actionSyncUserSieve(array $params, string $actor): array
    {
        $email = strtolower(trim((string)($params['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('A valid email is required');
        }

        if (!file_exists(self::MAILFLOW_SIEVE_CLI)) {
            return $this->error('MailFlow Sieve CLI not found; is the Email app installed?', [
                'cli' => self::MAILFLOW_SIEVE_CLI,
                'synced' => false,
            ]);
        }

        // Run as the email app user (nobody): -n so a missing sudoers rule fails
        // fast instead of hanging on a password prompt.
        $result = $this->execCommand('sudo', [
            '-n',
            '-u', self::MAILFLOW_RUNAS_USER,
            $this->resolvePhpBinary(),
            self::MAILFLOW_SIEVE_CLI,
            '--email=' . $email,
            '--json',
        ], 60);

        if (!$result['success']) {
            return $this->error('Sieve resync failed: ' . trim($result['output']), ['synced' => false]);
        }

        return $this->success([
            'synced' => true,
            'email' => $email,
            'detail' => trim($result['output']),
        ], "Resynced Sieve for {$email} by {$actor}");
    }

    // ==================== EMAIL AUTH (SPF / DKIM / DMARC) ====================

    /**
     * Live, read-only SPF/DKIM/DMARC status for a set of domains. Pure DNS
     * lookups; touches nothing on the server.
     */
    protected function actionAuthStatus(array $params, string $actor): array
    {
        $domains = $params['domains'] ?? [];
        if (!is_array($domains)) {
            return $this->error('domains must be an array');
        }
        $selector = preg_replace('/[^a-z0-9_-]/i', '', (string)($params['selector'] ?? 'default'));
        if ($selector === '') {
            $selector = 'default';
        }

        $results = [];
        foreach ($domains as $d) {
            $d = strtolower(trim((string)$d));
            if (!preg_match('/^(?=.{1,253}$)([a-z0-9](-?[a-z0-9])*\.)+[a-z]{2,}$/', $d)) {
                continue;
            }
            $results[] = $this->authForDomain($d, $selector);
            if (count($results) >= 250) {
                break;
            }
        }

        return $this->success(['domains' => $results, 'selector' => $selector]);
    }

    private function authForDomain(string $domain, string $selector): array
    {
        $spf = $this->firstMatchingTxt($this->lookupTxt($domain), 'v=spf1');
        $dmarc = $this->firstMatchingTxt($this->lookupTxt('_dmarc.' . $domain), 'v=DMARC1');
        $dkimTxts = $this->lookupTxt($selector . '._domainkey.' . $domain);
        $dkim = $this->firstMatchingTxt($dkimTxts, 'v=DKIM1') ?? $this->firstMatchingTxt($dkimTxts, 'p=');
        $localKey = file_exists("/etc/opendkim/keys/{$domain}/{$selector}.txt");

        return [
            'domain' => $domain,
            'spf' => $this->classifySpf($spf),
            'dkim' => $this->classifyDkim($dkim, $localKey),
            'dmarc' => $this->classifyDmarc($dmarc),
        ];
    }

    private function classifySpf(?string $v): array
    {
        if ($v === null) {
            return ['status' => 'missing', 'value' => null, 'detail' => 'No SPF record'];
        }
        if (preg_match('/-all\b/', $v)) {
            return ['status' => 'ok', 'value' => $v, 'detail' => 'Strict (-all)'];
        }
        if (preg_match('/\+all\b/', $v)) {
            return ['status' => 'warn', 'value' => $v, 'detail' => 'Allows all (+all) - unsafe'];
        }
        if (preg_match('/~all\b/', $v)) {
            return ['status' => 'warn', 'value' => $v, 'detail' => 'Soft fail (~all)'];
        }
        if (preg_match('/\?all\b/', $v)) {
            return ['status' => 'warn', 'value' => $v, 'detail' => 'Neutral (?all)'];
        }
        return ['status' => 'warn', 'value' => $v, 'detail' => 'No "all" mechanism'];
    }

    private function classifyDkim(?string $v, bool $localKey): array
    {
        if ($v !== null) {
            return ['status' => 'ok', 'value' => $v, 'detail' => 'Published'];
        }
        if ($localKey) {
            return ['status' => 'warn', 'value' => null, 'detail' => 'Key generated but not published in DNS'];
        }
        return ['status' => 'missing', 'value' => null, 'detail' => 'No DKIM key'];
    }

    private function classifyDmarc(?string $v): array
    {
        if ($v === null) {
            return ['status' => 'missing', 'value' => null, 'detail' => 'No DMARC record'];
        }
        if (preg_match('/\bp\s*=\s*reject\b/i', $v)) {
            return ['status' => 'ok', 'value' => $v, 'detail' => 'p=reject'];
        }
        if (preg_match('/\bp\s*=\s*quarantine\b/i', $v)) {
            return ['status' => 'ok', 'value' => $v, 'detail' => 'p=quarantine'];
        }
        if (preg_match('/\bp\s*=\s*none\b/i', $v)) {
            return ['status' => 'warn', 'value' => $v, 'detail' => 'p=none (monitor only)'];
        }
        return ['status' => 'warn', 'value' => $v, 'detail' => 'Policy unclear'];
    }

    private function lookupTxt(string $name): array
    {
        $out = [];
        $records = @dns_get_record($name, DNS_TXT);
        if (is_array($records)) {
            foreach ($records as $r) {
                if (!empty($r['txt'])) {
                    $out[] = $r['txt'];
                } elseif (!empty($r['entries']) && is_array($r['entries'])) {
                    $out[] = implode('', $r['entries']);
                }
            }
        }
        return $out;
    }

    private function firstMatchingTxt(array $txts, string $needle): ?string
    {
        foreach ($txts as $t) {
            if (stripos($t, $needle) !== false) {
                return $t;
            }
        }
        return null;
    }

    // ==================== QUARANTINE (hold + release foundation) ====================

    /**
     * Install quarantine spool, ingest script, and Postfix pipe transport.
     * The transport is defined in master.cf but NOT routed until canary wiring.
     */
    protected function actionSetupQuarantine(array $params, string $actor): array
    {
        $result = $this->setupQuarantineInternal($actor);
        if (!$result['success']) {
            return $this->error($result['error'] ?? 'Quarantine setup failed', $result);
        }
        return $this->success($result, "Quarantine infrastructure ready by {$actor}");
    }

    /**
     * Release a quarantined message back into Postfix for normal delivery.
     */
    protected function actionReleaseQuarantine(array $params, string $actor): array
    {
        $spoolPath = (string)($params['spool_path'] ?? '');
        $recipient = strtolower(trim((string)($params['recipient'] ?? '')));
        $sender = trim((string)($params['sender'] ?? ''));

        if (!$this->validateQuarantinePath($spoolPath)) {
            return $this->error('Invalid quarantine spool path');
        }
        if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return $this->error('A valid recipient is required');
        }
        if (!file_exists($spoolPath)) {
            return $this->error('Quarantine message file not found');
        }

        $raw = file_get_contents($spoolPath);
        if ($raw === false || $raw === '') {
            return $this->error('Quarantine message file is empty');
        }

        // Strip the routing header so the re-injected message is not re-held.
        $raw = $this->stripManagedHeaders($raw);

        if ($sender === '') {
            $sender = $this->extractSimpleHeader($raw, 'From') ?? 'postmaster@localhost';
        }
        $senderAddr = $this->stripAngleAddr($sender);
        if (!filter_var($senderAddr, FILTER_VALIDATE_EMAIL)) {
            $senderAddr = 'postmaster@localhost';
        }

        $sendmail = $this->findSendmail();
        if ($sendmail === null) {
            return $this->error('sendmail not found');
        }

        $cmd = $sendmail . ' -i -f ' . escapeshellarg($senderAddr) . ' ' . escapeshellarg($recipient);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            return $this->error('Failed to start sendmail');
        }
        fwrite($pipes[0], $raw);
        fclose($pipes[0]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0) {
            return $this->error('Release injection failed: ' . trim($stderr ?: "exit {$code}"));
        }

        // Postfix has accepted the re-injected message for delivery, so the held
        // copy is now redundant. Drop it so released messages do not accumulate as
        // orphaned spool files (the DB row keeps released_at/released_by for audit).
        @unlink($spoolPath);

        return $this->success(['released' => true, 'recipient' => $recipient], "Released quarantine message to {$recipient} by {$actor}");
    }

    /**
     * Delete a quarantined message file from the spool (DB row updated by API).
     */
    protected function actionDeleteQuarantineFile(array $params, string $actor): array
    {
        $spoolPath = (string)($params['spool_path'] ?? '');
        if (!$this->validateQuarantinePath($spoolPath)) {
            return $this->error('Invalid quarantine spool path');
        }
        if (file_exists($spoolPath) && !@unlink($spoolPath)) {
            return $this->error('Failed to delete quarantine file');
        }
        return $this->success(['deleted' => true], "Deleted quarantine file by {$actor}");
    }

    private function setupQuarantineInternal(string $actor): array
    {
        $result = ['spool' => false, 'ingest' => false, 'transport' => false];

        if (!is_dir(self::QUARANTINE_SPOOL)) {
            @mkdir(dirname(self::QUARANTINE_SPOOL), 0755, true);
            if (!@mkdir(self::QUARANTINE_SPOOL, 0750, true)) {
                return ['success' => false, 'error' => 'Failed to create quarantine spool', ...$result];
            }
        }
        // Owned by the pipe user so the ingest (run as www-data by Postfix) can
        // write held messages; the agent runs as root and can still read/release them.
        $this->execCommand('chown', ['-R', self::QUARANTINE_PIPE_USER . ':' . self::QUARANTINE_PIPE_USER, dirname(self::QUARANTINE_SPOOL)], 30);
        $result['spool'] = is_dir(self::QUARANTINE_SPOOL);

        $this->ensureDir(dirname(self::QUARANTINE_INGEST_DST));
        if (!file_exists(self::QUARANTINE_INGEST_SRC)) {
            return ['success' => false, 'error' => 'Ingest script source missing in agent bundle', ...$result];
        }
        if (file_exists(self::QUARANTINE_INGEST_DST)) {
            $this->backupFile(self::QUARANTINE_INGEST_DST, 'setupQuarantine', $actor);
        }
        if (!copy(self::QUARANTINE_INGEST_SRC, self::QUARANTINE_INGEST_DST)) {
            return ['success' => false, 'error' => 'Failed to deploy ingest script', ...$result];
        }
        @chmod(self::QUARANTINE_INGEST_DST, 0755);
        $result['ingest'] = file_exists(self::QUARANTINE_INGEST_DST);

        // Deploy the retention/digest sweeper + its daily cron (best-effort: a
        // missing cron must not fail quarantine setup).
        $result['maintenance'] = $this->ensureQuarantineMaintenance($actor);

        $transportOk = $this->ensureQuarantineTransport($actor);
        $result['transport'] = $transportOk;
        if (!$transportOk) {
            return ['success' => false, 'error' => 'Failed to configure Postfix quarantine transport', ...$result];
        }

        return array_merge($result, ['success' => true, 'status' => $this->quarantineStatus()]);
    }

    /**
     * Deploy the quarantine maintenance script and install its daily cron.
     * Idempotent; returns true once both the script and cron entry are present.
     */
    private function ensureQuarantineMaintenance(string $actor): bool
    {
        if (!file_exists(self::QUARANTINE_MAINT_SRC)) {
            return false;
        }
        $this->ensureDir(dirname(self::QUARANTINE_MAINT_DST));
        if (file_exists(self::QUARANTINE_MAINT_DST)) {
            $this->backupFile(self::QUARANTINE_MAINT_DST, 'setupQuarantine', $actor);
        }
        if (!copy(self::QUARANTINE_MAINT_SRC, self::QUARANTINE_MAINT_DST)) {
            return false;
        }
        @chmod(self::QUARANTINE_MAINT_DST, 0755);

        // Run daily at 03:30 as the spool owner (same user as the ingest), so it
        // can both delete held .eml files and update the panel DB. An explicit php
        // binary avoids any PATH/exec-bit surprises under cron.
        $php = is_file('/usr/bin/php') ? '/usr/bin/php' : $this->resolvePhpBinary();
        $cron = "# DEVCON Mail Security - quarantine retention + digest (managed; do not edit)\n"
            . "SHELL=/bin/sh\n"
            . "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n"
            . "30 3 * * * " . self::QUARANTINE_PIPE_USER . " " . $php . " " . self::QUARANTINE_MAINT_DST . " >/dev/null 2>&1\n";

        if (file_exists(self::QUARANTINE_MAINT_CRON)) {
            $current = @file_get_contents(self::QUARANTINE_MAINT_CRON);
            if ($current === $cron) {
                return true; // already current
            }
            $this->backupFile(self::QUARANTINE_MAINT_CRON, 'setupQuarantine', $actor);
        }
        if (file_put_contents(self::QUARANTINE_MAINT_CRON, $cron) === false) {
            return false;
        }
        @chmod(self::QUARANTINE_MAINT_CRON, 0644);
        return true;
    }

    /**
     * Run quarantine retention (+ optional digest) on demand via the deployed
     * sweeper, returning its JSON summary. Mirrors the daily cron run.
     */
    protected function actionMaintainQuarantine(array $params, string $actor): array
    {
        // Self-heal: make sure the script + cron exist before running.
        $this->ensureQuarantineMaintenance($actor);

        if (!file_exists(self::QUARANTINE_MAINT_DST)) {
            return $this->error('Quarantine maintenance script is not deployed');
        }

        $args = [self::QUARANTINE_MAINT_DST, '--json'];
        if (!empty($params['dry_run'])) {
            $args[] = '--dry-run';
        }
        if (!empty($params['digest_only'])) {
            $args[] = '--digest-only';
        } elseif (!empty($params['purge_only'])) {
            $args[] = '--purge-only';
        }

        $php = is_file('/usr/bin/php') ? '/usr/bin/php' : $this->resolvePhpBinary();
        $run = $this->execCommand($php, $args, 120);

        // Output is stdout+stderr combined; pick the last line that is a JSON object.
        $summary = null;
        $lines = preg_split('/\r?\n/', trim((string)($run['output'] ?? ''))) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && array_key_exists('success', $decoded)) {
                $summary = $decoded;
                break;
            }
        }
        if (!is_array($summary)) {
            return $this->error('Maintenance run produced no parseable summary', ['raw' => $run['output'] ?? '']);
        }
        if (empty($summary['success'])) {
            return $this->error('Quarantine maintenance failed', ['summary' => $summary]);
        }

        return $this->success($summary, sprintf(
            'Quarantine maintenance complete (expired %d, purged %d, swept %d) by %s',
            (int)($summary['expired'] ?? 0),
            (int)($summary['purged_rows'] ?? 0),
            (int)($summary['orphans_swept'] ?? 0),
            $actor
        ));
    }

    /**
     * Deploy the event ingester script and install its per-minute cron.
     * Idempotent; returns true once both the script and cron entry are present.
     */
    private function ensureEventSync(string $actor): bool
    {
        if (!file_exists(self::EVENT_SYNC_SRC)) {
            return false;
        }
        $this->ensureDir(dirname(self::EVENT_SYNC_DST));
        if (file_exists(self::EVENT_SYNC_DST)) {
            $this->backupFile(self::EVENT_SYNC_DST, 'setupQuarantine', $actor);
        }
        if (!copy(self::EVENT_SYNC_SRC, self::EVENT_SYNC_DST)) {
            return false;
        }
        @chmod(self::EVENT_SYNC_DST, 0755);

        // Every minute as the panel web user (it can read the DB config and reach
        // the localhost controller). Explicit php binary avoids PATH surprises.
        $php = is_file('/usr/bin/php') ? '/usr/bin/php' : $this->resolvePhpBinary();
        $cron = "# DEVCON Mail Security - event ingestion (managed; do not edit)\n"
            . "SHELL=/bin/sh\n"
            . "PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin\n"
            . "* * * * * " . self::QUARANTINE_PIPE_USER . " " . $php . " " . self::EVENT_SYNC_DST . " >/dev/null 2>&1\n";

        if (file_exists(self::EVENT_SYNC_CRON)) {
            $current = @file_get_contents(self::EVENT_SYNC_CRON);
            if ($current === $cron) {
                return true; // already current
            }
            $this->backupFile(self::EVENT_SYNC_CRON, 'setupQuarantine', $actor);
        }
        if (file_put_contents(self::EVENT_SYNC_CRON, $cron) === false) {
            return false;
        }
        @chmod(self::EVENT_SYNC_CRON, 0644);
        return true;
    }

    /**
     * Drain the Rspamd history into mail_security_events on demand, returning the
     * ingester's JSON summary. Mirrors the per-minute cron run.
     */
    protected function actionSyncEvents(array $params, string $actor): array
    {
        // Self-heal: make sure the script + cron exist before running.
        $this->ensureEventSync($actor);

        if (!file_exists(self::EVENT_SYNC_DST)) {
            return $this->error('Event ingester script is not deployed');
        }

        $args = [self::EVENT_SYNC_DST, '--json'];
        if (!empty($params['reset'])) {
            $args[] = '--reset';
        }

        $php = is_file('/usr/bin/php') ? '/usr/bin/php' : $this->resolvePhpBinary();
        $run = $this->execCommand($php, $args, 60);

        $summary = null;
        $lines = preg_split('/\r?\n/', trim((string)($run['output'] ?? ''))) ?: [];
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '' || $line[0] !== '{') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && array_key_exists('success', $decoded)) {
                $summary = $decoded;
                break;
            }
        }
        if (!is_array($summary)) {
            return $this->error('Event sync produced no parseable summary', ['raw' => $run['output'] ?? '']);
        }
        if (empty($summary['success'])) {
            return $this->error('Event sync failed', ['summary' => $summary]);
        }

        return $this->success($summary, sprintf(
            'Event sync complete (fetched %d, inserted %d) by %s',
            (int)($summary['fetched'] ?? 0),
            (int)($summary['inserted'] ?? 0),
            $actor
        ));
    }

    private function ensureQuarantineTransport(string $actor): bool
    {
        if (!file_exists(self::POSTFIX_MASTER)) {
            return false;
        }
        $original = file_get_contents(self::POSTFIX_MASTER);
        if ($original === false) {
            return false;
        }

        $endMarker = '# END DEVCON Mail Security quarantine transport';
        $block = self::QUARANTINE_TRANSPORT_MARKER . "\n"
            . "devcon-quarantine unix  -       n       n       -       -       pipe\n"
            . "  flags=Rq user=" . self::QUARANTINE_PIPE_USER . " argv=" . self::QUARANTINE_INGEST_DST . " \${recipient}\n"
            . $endMarker;

        if (str_contains($original, self::QUARANTINE_TRANSPORT_MARKER)) {
            // Replace the managed block in place so a stale definition (e.g. an old
            // "user=postfix" line that Postfix rejects) self-heals on re-provision.
            $pattern = '/' . preg_quote(self::QUARANTINE_TRANSPORT_MARKER, '/') . '.*?' . preg_quote($endMarker, '/') . '/s';
            $content = preg_replace($pattern, $block, $original, 1);
            if ($content === null) {
                return false;
            }
            if ($content === $original) {
                return true; // already current
            }
        } else {
            $content = rtrim($original) . "\n\n" . $block . "\n";
        }

        $this->backupFile(self::POSTFIX_MASTER, 'setupQuarantine', $actor);
        if (file_put_contents(self::POSTFIX_MASTER, $content) === false) {
            return false;
        }

        $check = $this->execCommand('postfix', ['check'], 30);
        if (!$check['success']) {
            // Never leave a broken master.cf behind.
            file_put_contents(self::POSTFIX_MASTER, $original);
            return false;
        }
        $this->execCommand('systemctl', ['reload', 'postfix'], 30);
        return true;
    }

    private function quarantineStatus(): array
    {
        $transportReady = false;
        if (file_exists(self::POSTFIX_MASTER)) {
            $mc = file_get_contents(self::POSTFIX_MASTER);
            $transportReady = is_string($mc) && str_contains($mc, 'devcon-quarantine');
        }
        return [
            'ready' => is_dir(self::QUARANTINE_SPOOL)
                && file_exists(self::QUARANTINE_INGEST_DST)
                && $transportReady,
            'spool_path' => self::QUARANTINE_SPOOL,
            'transport' => 'devcon-quarantine',
            'transport_wired' => false, // explicit routing is a later canary step
        ];
    }

    private function validateQuarantinePath(string $path): bool
    {
        $realSpool = realpath(self::QUARANTINE_SPOOL);
        if ($realSpool === false) {
            return str_starts_with($path, self::QUARANTINE_SPOOL . '/');
        }
        $realFile = realpath($path);
        if ($realFile === false) {
            return str_starts_with($path, self::QUARANTINE_SPOOL . '/')
                && preg_match('/^[a-f0-9]{32}\.eml$/', basename($path));
        }
        return str_starts_with($realFile, $realSpool . DIRECTORY_SEPARATOR);
    }

    private function findSendmail(): ?string
    {
        foreach (['/usr/sbin/sendmail', '/usr/lib/sendmail'] as $p) {
            if (is_executable($p)) {
                return $p;
            }
        }
        $which = $this->execCommand('command', ['-v', 'sendmail'], 5);
        if ($which['success'] && trim($which['output']) !== '') {
            return trim(explode("\n", $which['output'])[0]);
        }
        return null;
    }

    private function extractSimpleHeader(string $raw, string $name): ?string
    {
        if (preg_match('/^' . preg_quote($name, '/') . ':\s*(.+?)(?:\r?\n(?![\t ]|\r?\n)|$)/mis', $raw, $m)) {
            return trim(preg_replace('/\r?\n[\t ]+/', ' ', $m[1]) ?? $m[1]);
        }
        return null;
    }

    private function stripAngleAddr(string $s): string
    {
        if (preg_match('/<([^>]+)>/', $s, $m)) {
            return strtolower(trim($m[1]));
        }
        return strtolower(trim($s));
    }

    /**
     * Remove the quarantine routing header so a released message is not
     * immediately re-held by header_checks when re-injected.
     */
    private function stripManagedHeaders(string $raw): string
    {
        $sep = strpos($raw, "\r\n\r\n");
        if ($sep === false) {
            $sep = strpos($raw, "\n\n");
        }
        if ($sep === false) {
            return $raw;
        }
        $headers = substr($raw, 0, $sep);
        $body = substr($raw, $sep);
        $headers = preg_replace(
            '/^' . preg_quote(self::QUARANTINE_HEADER, '/') . ':.*(?:\r?\n[ \t].*)*\r?\n?/mi',
            '',
            $headers
        );
        return $headers . $body;
    }

    // ==================== DELIVERY WIRING (live: milter + quarantine routing) ====================

    /**
     * Point Postfix's inbound milter at Rspamd and enable quarantine routing.
     *
     * SAFETY:
     *  - FAIL-OPEN: milter_default_action=accept, so a Rspamd outage degrades to
     *    plain delivery, never a block.
     *  - Only smtpd_milters is touched (inbound). non_smtpd_milters is left alone,
     *    so released/locally-submitted mail is not re-scanned (no loop).
     *  - Existing milters (e.g. OpenDKIM) are preserved.
     *  - postfix check / rspamadm configtest gate every reload.
     */
    protected function actionWireMilter(array $params, string $actor): array
    {
        if (!$this->commandExists('postconf')) {
            return $this->error('postconf not found; Postfix is not installed.');
        }
        if (!$this->serviceActive('rspamd')) {
            return $this->error('Rspamd is not running. Start the engine before wiring delivery.');
        }

        if (file_exists(self::POSTFIX_MAIN)) {
            $this->backupFile(self::POSTFIX_MAIN, 'wireMilter', $actor);
        }

        // 1. Ensure quarantine spool + transport + ingest exist.
        $q = $this->setupQuarantineInternal($actor);
        if (!($q['success'] ?? false)) {
            return $this->error('Quarantine setup failed: ' . ($q['error'] ?? 'unknown'));
        }

        // 2. Rspamd: add the quarantine header for the "add header" action band.
        $this->writeConfig(self::RSPAMD_LOCAL . '/milter_headers.conf', $this->renderMilterHeadersConf(), $actor);
        $check = $this->execCommand('rspamadm', ['configtest'], 30);
        if (!$check['success']) {
            return $this->error('Rspamd configtest failed (delivery NOT wired): ' . $check['output']);
        }
        $this->execCommand('systemctl', ['reload', 'rspamd'], 30);

        // 3. Postfix regexp rule routing flagged mail to the hold transport.
        $this->writeHeaderChecks($actor);

        // 4. Wire smtpd_milters (preserve existing), fail-open defaults.
        $smtpd = $this->getPostconf('smtpd_milters');
        $this->setPostconf('smtpd_milters', $this->addToList($smtpd, self::RSPAMD_MILTER));
        $this->setPostconf('milter_default_action', 'accept');
        $this->setPostconf('milter_protocol', '6');

        // 5. Add our header_checks map (preserve existing maps). We use regexp:
        // (not pcre:) because regexp is ALWAYS compiled into Postfix, whereas pcre:
        // needs the postfix-pcre package. A missing pcre map type is fail-CLOSED:
        // `postfix check` still passes, but cleanup defers EVERY message at runtime
        // ("unsupported dictionary type: pcre"). Also migrate any stale pcre: entry
        // a previous build may have written, so re-wiring self-heals such a server.
        $hc = $this->removeFromList($this->getPostconf('header_checks'), 'pcre:' . self::QUARANTINE_HEADER_CHECKS);
        $this->setPostconf('header_checks', $this->addToList($hc, 'regexp:' . self::QUARANTINE_HEADER_CHECKS));

        // 6. Validate + reload Postfix. On failure, roll back the milter wiring.
        $pf = $this->execCommand('postfix', ['check'], 30);
        if (!$pf['success']) {
            $this->setPostconf('smtpd_milters', $this->removeFromList($this->getPostconf('smtpd_milters'), self::RSPAMD_MILTER));
            $hc = $this->removeFromList($this->getPostconf('header_checks'), 'regexp:' . self::QUARANTINE_HEADER_CHECKS);
            $this->setPostconf('header_checks', $this->removeFromList($hc, 'pcre:' . self::QUARANTINE_HEADER_CHECKS));
            return $this->error('postfix check failed; wiring rolled back: ' . $pf['output']);
        }
        $this->execCommand('systemctl', ['reload', 'postfix'], 30);

        return $this->success([
            'wired' => true,
            'delivery' => $this->deliveryState(),
        ], "Delivery wired to Rspamd (fail-open) by {$actor}");
    }

    /**
     * Disconnect Rspamd from delivery: remove the milter and quarantine routing.
     * Leaves Rspamd/quarantine infrastructure intact (harmless while unwired).
     */
    protected function actionUnwireMilter(array $params, string $actor): array
    {
        if (!$this->commandExists('postconf')) {
            return $this->error('postconf not found; Postfix is not installed.');
        }
        if (file_exists(self::POSTFIX_MAIN)) {
            $this->backupFile(self::POSTFIX_MAIN, 'unwireMilter', $actor);
        }

        $this->setPostconf('smtpd_milters', $this->removeFromList($this->getPostconf('smtpd_milters'), self::RSPAMD_MILTER));
        // Remove both the current regexp: map and any legacy pcre: entry.
        $hc = $this->removeFromList($this->getPostconf('header_checks'), 'regexp:' . self::QUARANTINE_HEADER_CHECKS);
        $this->setPostconf('header_checks', $this->removeFromList($hc, 'pcre:' . self::QUARANTINE_HEADER_CHECKS));

        $pf = $this->execCommand('postfix', ['check'], 30);
        if (!$pf['success']) {
            return $this->error('postfix check failed after unwiring: ' . $pf['output']);
        }
        $this->execCommand('systemctl', ['reload', 'postfix'], 30);

        return $this->success([
            'wired' => false,
            'delivery' => $this->deliveryState(),
        ], "Delivery unwired from Rspamd by {$actor}");
    }

    protected function actionDeliveryStatus(array $params, string $actor): array
    {
        return $this->success(['delivery' => $this->deliveryState()]);
    }

    /**
     * Tail a mail-related log for live monitoring. Read-only; the source is an
     * allowlisted key (never a user-supplied path).
     */
    protected function actionTailLog(array $params, string $actor): array
    {
        $sources = [
            'mail' => '/var/log/mail.log',
            'rspamd' => '/var/log/rspamd/rspamd.log',
        ];
        $src = (string)($params['source'] ?? 'mail');
        if (!isset($sources[$src])) {
            return $this->error('Invalid log source');
        }
        $path = $sources[$src];
        $lines = max(10, min((int)($params['lines'] ?? 120), 500));

        if (!file_exists($path)) {
            return $this->success(['source' => $src, 'path' => $path, 'lines' => [], 'available' => false]);
        }

        $result = $this->execCommand('tail', ['-n', (string)$lines, $path], 15);
        if (!$result['success']) {
            return $this->error('Failed to read log: ' . $result['output']);
        }

        $out = $result['output'] === '' ? [] : explode("\n", $result['output']);
        $filter = trim((string)($params['filter'] ?? ''));
        if ($filter !== '') {
            $out = array_values(array_filter($out, static fn($l) => stripos($l, $filter) !== false));
        }

        return $this->success([
            'source' => $src,
            'path' => $path,
            'lines' => $out,
            'available' => true,
        ]);
    }

    // ==================== LOCAL RESOLVER (unbound for DNSBLs) ====================

    /**
     * Install + configure a local unbound recursor on 127.0.0.1:5335 and point
     * Rspamd at it. Standalone entry point so it can be re-run without a full
     * reinstall.
     */
    protected function actionSetupResolver(array $params, string $actor): array
    {
        $result = $this->setupResolverInternal($actor);
        $message = $result['configured']
            ? "Local resolver configured; Rspamd now uses 127.0.0.1:" . self::RESOLVER_PORT
            : 'Resolver not configured; Rspamd left on the system resolver (' . ($result['reason'] ?? 'unknown') . ')';
        return $this->success($result, $message);
    }

    /**
     * Best-effort, fail-safe resolver setup. Returns a structured result; never
     * throws. Rspamd is only pointed at unbound once unbound actually answers on
     * the dedicated port, so a failure here can never regress DNS.
     */
    private function setupResolverInternal(string $actor): array
    {
        $steps = [];

        $install = $this->execCommand('env', [
            'DEBIAN_FRONTEND=noninteractive', 'apt-get', 'install', '-y', 'unbound',
        ], 300);
        $steps['unbound_installed'] = $install['success'];
        if (!$install['success']) {
            return ['success' => false, 'configured' => false, 'steps' => $steps, 'reason' => 'apt install unbound failed'];
        }

        // Our dedicated recursor drop-in (alt port; coexists with any :53 server).
        $this->ensureDir(self::UNBOUND_CONF_D);
        @file_put_contents(self::UNBOUND_RESOLVER_CONF, $this->renderUnboundConf());
        @chmod(self::UNBOUND_RESOLVER_CONF, 0644);
        $steps['drop_in_written'] = file_exists(self::UNBOUND_RESOLVER_CONF);

        // Supersede any other drop-in already binding our port (e.g. a prior manual
        // attempt) so unbound does not fail trying to bind the same port twice.
        $steps['superseded_dropins'] = $this->disableOtherResolverPortDropins();

        $running = $this->bringUpUnbound($steps);

        if ($running && $this->resolverAnswers()) {
            // writeConfig backs up any pre-existing options.inc before overwriting.
            $steps['rspamd_pointed'] = $this->writeConfig(
                self::RSPAMD_LOCAL . '/options.inc',
                $this->renderRspamdDnsConf(),
                $actor
            );
            $this->execCommand('systemctl', ['reload', 'rspamd'], 30);
            return ['success' => true, 'configured' => true, 'steps' => $steps];
        }

        // Fail-safe: leave Rspamd on the system resolver. Do NOT write options.inc.
        $steps['rspamd_pointed'] = false;
        return [
            'success' => true,
            'configured' => false,
            'steps' => $steps,
            'reason' => 'unbound did not answer on 127.0.0.1:' . self::RESOLVER_PORT,
        ];
    }

    /**
     * Start unbound and, if it fails because a pre-existing drop-in tries to bind
     * :53 while another server already owns it, disable those drop-ins and retry.
     */
    private function bringUpUnbound(array &$steps): bool
    {
        $this->execCommand('systemctl', ['enable', 'unbound'], 20);
        $this->execCommand('systemctl', ['restart', 'unbound'], 30);

        if ($this->serviceActive('unbound') && $this->resolverAnswers()) {
            $steps['unbound_running'] = true;
            return true;
        }

        // Remediate the common conflict: a stale drop-in binds 127.0.0.1:53 (or a
        // bare interface defaulting to 53) while PowerDNS/systemd-resolved owns it.
        if ($this->port53HeldByOther()) {
            $disabled = $this->disableConflictingUnboundDropins();
            $steps['disabled_conflicting_dropins'] = $disabled;
            if (!empty($disabled)) {
                $this->execCommand('systemctl', ['restart', 'unbound'], 30);
            }
        }

        $ok = $this->serviceActive('unbound') && $this->resolverAnswers();
        $steps['unbound_running'] = $ok;
        return $ok;
    }

    /**
     * Functional probe: ask the local resolver (UDP 127.0.0.1:5335) to resolve a
     * stable A record. Dependency-free (raw DNS query); returns true only if a
     * real answer comes back.
     */
    private function resolverAnswers(): bool
    {
        $id = "\x12\x34";
        $flags = "\x01\x00";                       // recursion desired
        $counts = "\x00\x01\x00\x00\x00\x00\x00\x00"; // 1 question
        $qname = '';
        foreach (['a', 'root-servers', 'net'] as $label) {
            $qname .= chr(strlen($label)) . $label;
        }
        $qname .= "\x00";
        $packet = $id . $flags . $counts . $qname . "\x00\x01\x00\x01"; // A, IN

        $fp = @fsockopen('udp://127.0.0.1', self::RESOLVER_PORT, $errno, $errstr, 2);
        if (!$fp) {
            return false;
        }
        stream_set_timeout($fp, 2);
        @fwrite($fp, $packet);
        $resp = @fread($fp, 512);
        @fclose($fp);

        if ($resp === false || strlen($resp) < 12) {
            return false;
        }
        $hdr = @unpack('nid/nflags/nqd/nan/nns/nar', substr($resp, 0, 12));
        if (!$hdr) {
            return false;
        }
        // Response bit set and at least one answer record.
        return ($hdr['flags'] & 0x8000) !== 0 && $hdr['an'] > 0;
    }

    /** True if :53 is bound by a process that is not unbound. */
    private function port53HeldByOther(): bool
    {
        $r = $this->execCommand('ss', ['-lntuHp', 'sport = :53'], 10);
        $out = trim($r['output'] ?? '');
        if ($out === '') {
            return false;
        }
        return !str_contains($out, 'unbound');
    }

    /**
     * Disable (rename to .disabled) any unbound drop-in other than ours that tries
     * to bind the default DNS port (explicit "port: 53" or a bare interface with
     * no @port). Only called once we know :53 is owned by another daemon, so this
     * cannot break a working unbound-on-53 system resolver.
     */
    private function disableConflictingUnboundDropins(): array
    {
        $disabled = [];
        foreach (glob(self::UNBOUND_CONF_D . '/*.conf') ?: [] as $file) {
            if ($file === self::UNBOUND_RESOLVER_CONF) {
                continue;
            }
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            $bindsDefaultPort = preg_match('/^\s*port\s*:\s*53\b/m', $content)
                || preg_match('/^\s*interface\s*:\s*\S+@53\b/m', $content)
                || (preg_match('/^\s*interface\s*:/m', $content) && !preg_match('/@\d+/', $content));
            if ($bindsDefaultPort && @rename($file, $file . '.disabled')) {
                $disabled[] = basename($file);
            }
        }
        return $disabled;
    }

    /**
     * Disable any unbound drop-in other than ours that binds our resolver port,
     * preventing a double-bind failure when re-running over a prior manual setup.
     */
    private function disableOtherResolverPortDropins(): array
    {
        $disabled = [];
        $port = self::RESOLVER_PORT;
        foreach (glob(self::UNBOUND_CONF_D . '/*.conf') ?: [] as $file) {
            if ($file === self::UNBOUND_RESOLVER_CONF) {
                continue;
            }
            $content = @file_get_contents($file);
            if ($content === false) {
                continue;
            }
            if (preg_match('/@' . $port . '\b/', $content) && @rename($file, $file . '.disabled')) {
                $disabled[] = basename($file);
            }
        }
        return $disabled;
    }

    private function renderUnboundConf(): string
    {
        $port = self::RESOLVER_PORT;
        return <<<CONF
# Managed by DEVCON Mail Security. Local recursive resolver for Rspamd DNSBLs.
# Bound to 127.0.0.1:{$port} so it never collides with a :53 DNS server
# (PowerDNS, systemd-resolved, ...) already running on the host.
server:
    interface: 127.0.0.1@{$port}
    do-ip6: no
    access-control: 127.0.0.0/8 allow
    access-control: 0.0.0.0/0 refuse
    hide-identity: yes
    hide-version: yes
    qname-minimisation: yes
    prefetch: yes
    harden-glue: yes
    harden-dnssec-stripped: yes
    edns-buffer-size: 1232

CONF;
    }

    private function renderRspamdDnsConf(): string
    {
        $port = self::RESOLVER_PORT;
        return "# Managed by DEVCON Mail Security. Use the local unbound recursor.\n"
            . "dns {\n  nameserver = [\"127.0.0.1:{$port}\"];\n}\n";
    }

    private function deliveryState(): array
    {
        $smtpd = $this->getPostconf('smtpd_milters');
        $hc = $this->getPostconf('header_checks');
        return [
            'milter_present' => str_contains($smtpd, self::RSPAMD_MILTER) || str_contains($smtpd, ':11332'),
            'smtpd_milters' => $smtpd,
            'milter_default_action' => $this->getPostconf('milter_default_action'),
            'fail_open' => strtolower(trim($this->getPostconf('milter_default_action'))) === 'accept',
            'quarantine_routing' => str_contains($hc, self::QUARANTINE_HEADER_CHECKS),
            'header_checks' => $hc,
        ];
    }

    private function renderMilterHeadersConf(): string
    {
        // The built-in "spam-header" routine fires only when the metric action is
        // "add header" (i.e. score is in the spam..reject band) - exactly the
        // messages we want to hold in quarantine. (There is no "x-spam-header"
        // routine; using that name makes Rspamd warn and silently add nothing.)
        $header = self::QUARANTINE_HEADER;
        return <<<CONF
# Managed by DEVCON Mail Security. Do not edit by hand.
extended_spam_headers = true;
use = ["spam-header"];
routines {
  spam-header {
    header = "{$header}";
    value = "yes";
  }
}

CONF;
    }

    private function writeHeaderChecks(string $actor): void
    {
        if (file_exists(self::QUARANTINE_HEADER_CHECKS)) {
            $this->backupFile(self::QUARANTINE_HEADER_CHECKS, 'wireMilter', $actor);
        }
        // POSIX ERE (regexp: table): use [[:space:]] not \s (pcre-style \s is read
        // literally by regexp: and never matches). Append NO flag: Postfix
        // header_checks are case-INSENSITIVE by default and the trailing flags only
        // TOGGLE that -- a /i would flip matching to case-SENSITIVE and miss
        // "YES"/"Yes". With no flag, "yes"/"YES"/"Yes" all match (the milter writes
        // lowercase "yes", but re-injected or hand-edited headers may vary).
        $rule = "# Managed by DEVCON Mail Security. Routes flagged mail to the quarantine hold transport.\n"
            . '/^' . self::QUARANTINE_HEADER . ':[[:space:]]*yes/  FILTER devcon-quarantine:dummy' . "\n";
        file_put_contents(self::QUARANTINE_HEADER_CHECKS, $rule);
        @chmod(self::QUARANTINE_HEADER_CHECKS, 0644);
    }

    private function getPostconf(string $key): string
    {
        $result = $this->execCommand('postconf', ['-h', $key], 10);
        return $result['success'] ? trim($result['output']) : '';
    }

    private function setPostconf(string $key, string $value): bool
    {
        $result = $this->execCommand('postconf', ['-e', $key . '=' . $value], 15);
        return $result['success'];
    }

    /**
     * Add an entry to a Postfix space/comma separated list, preserving existing
     * entries and avoiding duplicates.
     */
    private function addToList(string $current, string $entry): string
    {
        $parts = preg_split('/[\s,]+/', trim($current), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!in_array($entry, $parts, true)) {
            $parts[] = $entry;
        }
        return implode(' ', $parts);
    }

    private function removeFromList(string $current, string $entry): string
    {
        $parts = preg_split('/[\s,]+/', trim($current), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_values(array_filter($parts, static fn($p) => $p !== $entry));
        return implode(' ', $parts);
    }

    // ==================== HELPERS ====================

    private function renderActionsConf(float $spam, float $reject): string
    {
        // Rspamd merges local.d/actions.conf into the actions block.
        // The 'discard' milter action is disabled unless explicitly declared with
        // no_threshold; without it task:set_pre_result('discard') is ignored (the
        // message keeps its score-based action). The mail flow rules engine's
        // 'delete' action depends on this.
        $greylist = max(0.0, $spam - 2.0);
        return "# Managed by DEVCON Mail Security. Do not edit by hand.\n"
            . "reject = {$reject};\n"
            . "add_header = {$spam};\n"
            . "greylist = {$greylist};\n"
            . "discard = {\n  flags = [\"no_threshold\"];\n}\n";
    }

    /**
     * Phishing module options (merged into the stock phishing{} block). OpenPhish
     * must use the GitHub raw feed because Rspamd does not follow the 302 redirect
     * served by openphish.com; Phishtank is a free DNS feed (phishtank.rspamd.com).
     * Produces PHISHING / PHISHED_OPENPHISH / PHISHED_PHISHTANK -> 'phish' events.
     */
    private function renderPhishingConf(): string
    {
        return "# Managed by DEVCON Mail Security.\n"
            . "openphish_enabled = true;\n"
            . "openphish_premium = false;\n"
            . "openphish_map = \"https://raw.githubusercontent.com/openphish/public_feed/refs/heads/main/feed.txt\";\n"
            . "phishtank_enabled = true;\n";
    }

    /**
     * Redis-backed sender reputation (merged into the stock reputation{} block).
     * IP/SPF/DKIM reputation nudge the score up or down based on history; uses the
     * local redis configured by install. Read-only effect on scoring (fail-open).
     */
    private function renderReputationConf(): string
    {
        // The selector TYPE is the quoted block label (selector "ip" {}), not a
        // `type =` key inside the block - the latter yields "unknown selector".
        $backend = "backend \"redis\" { servers = \"127.0.0.1:6379\"; }";
        return "# Managed by DEVCON Mail Security.\n"
            . "rules {\n"
            . "  IP_REPUTATION = {\n"
            . "    selector \"ip\" {}\n"
            . "    {$backend}\n"
            . "    symbol = \"IP_REPUTATION\";\n"
            . "  }\n"
            . "  SPF_REPUTATION = {\n"
            . "    selector \"spf\" {}\n"
            . "    {$backend}\n"
            . "    symbol = \"SPF_REPUTATION\";\n"
            . "  }\n"
            . "  DKIM_REPUTATION = {\n"
            . "    selector \"dkim\" {}\n"
            . "    {$backend}\n"
            . "    symbol = \"DKIM_REPUTATION\";\n"
            . "  }\n"
            . "}\n";
    }

    /**
     * Write the phishing + reputation module configs, then validate the whole
     * Rspamd config with `rspamadm configtest`. If validation fails we restore
     * the previous state (or remove the files we just created) and report the
     * error, so a syntax slip can never leave the live engine unable to start.
     */
    private function setupPhishingReputation(string $actor): array
    {
        $files = [
            self::RSPAMD_LOCAL . '/phishing.conf'   => $this->renderPhishingConf(),
            self::RSPAMD_LOCAL . '/reputation.conf' => $this->renderReputationConf(),
        ];

        $existedBefore = [];
        $prevContent   = [];
        foreach ($files as $path => $content) {
            $existedBefore[$path] = file_exists($path);
            $prevContent[$path]   = $existedBefore[$path] ? (string) @file_get_contents($path) : null;
            $this->writeConfig($path, $content, $actor);
        }

        $test = $this->execCommand('rspamadm', ['configtest'], 30);
        if (!($test['success'] ?? false)) {
            foreach ($files as $path => $_) {
                if ($existedBefore[$path]) {
                    @file_put_contents($path, $prevContent[$path]);
                } else {
                    @unlink($path);
                }
            }
            return ['success' => false, 'error' => 'configtest rejected phishing/reputation config: ' . trim((string) ($test['output'] ?? ''))];
        }

        return ['success' => true];
    }

    /**
     * Write the three anti-spoofing maps. VIP display names are normalised to
     * alphanumeric-lowercase (the engine map is a 'set' that splits on whitespace,
     * and the Lua normalises lookups the same way). Domains/senders are plain
     * lowercased tokens. Empty maps are valid (the rule simply never fires).
     */
    private function writeImpersonationMaps(array $imp, string $actor, array &$written): void
    {
        $this->ensureDir(self::RSPAMD_MAPS);
        $sets = [
            self::IMP_MAP_VIP     => ['values' => $imp['vip_names'] ?? [],        'name' => true],
            self::IMP_MAP_DOMAINS => ['values' => $imp['protected_domains'] ?? [], 'name' => false],
            self::IMP_MAP_EXEMPT  => ['values' => $imp['exempt_senders'] ?? [],    'name' => false],
        ];
        foreach ($sets as $map => $spec) {
            $clean = [];
            foreach ((array) $spec['values'] as $v) {
                if ($spec['name']) {
                    $norm = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $v));
                } else {
                    $norm = strtolower(trim((string) $v));
                    if (preg_match('/\s/', $norm)) {
                        continue;
                    }
                }
                if ($norm !== '') {
                    $clean[] = $norm;
                }
            }
            $clean = array_values(array_unique($clean));
            $path = self::RSPAMD_MAPS . '/' . $map . '.map';
            $content = "# Managed by DEVCON Mail Security. Do not edit by hand.\n"
                . implode("\n", $clean) . (empty($clean) ? '' : "\n");
            if (file_exists($path)) {
                $this->backupFile($path, 'exportMaps', $actor);
            }
            file_put_contents($path, $content);
            @chmod($path, 0644);
            $written[$map] = count($clean);
        }

        // Lookalike runtime config (enabled + sensitivity) as a watched map so
        // panel toggles apply live without re-provisioning the engine.
        $lookPath = self::RSPAMD_MAPS . '/' . self::LOOKALIKE_MAP . '.map';
        if (file_exists($lookPath)) {
            $this->backupFile($lookPath, 'exportMaps', $actor);
        }
        file_put_contents($lookPath, $this->lookalikeConfigContent($imp['lookalike'] ?? []));
        @chmod($lookPath, 0644);
        $written[self::LOOKALIKE_MAP] = 1;
    }

    /**
     * Render the key=value body for the lookalike config map. Defaults to
     * enabled + medium sensitivity so a missing payload is always safe.
     */
    private function lookalikeConfigContent(array $look): string
    {
        $enabled = !array_key_exists('enabled', $look) || (bool) $look['enabled'] ? '1' : '0';
        $sens = strtolower((string) ($look['sensitivity'] ?? 'medium'));
        if (!in_array($sens, ['low', 'medium', 'high'], true)) {
            $sens = 'medium';
        }
        return "# Managed by DEVCON Mail Security. Do not edit by hand.\n"
            . "enabled={$enabled}\n"
            . "sensitivity={$sens}\n";
    }

    /**
     * Lua body (no markers) for the two anti-spoofing symbols. Reads the maps as
     * 'set' maps and registers MAILSEC_CEO_SPOOF + MAILSEC_INTERNAL_SPOOF. All
     * fail-open: a hit only adds score, the thresholds decide the outcome.
     */
    private function renderImpersonationLua(): string
    {
        $vip = self::RSPAMD_MAPS . '/' . self::IMP_MAP_VIP . '.map';
        $dom = self::RSPAMD_MAPS . '/' . self::IMP_MAP_DOMAINS . '.map';
        $exempt = self::RSPAMD_MAPS . '/' . self::IMP_MAP_EXEMPT . '.map';
        $look = self::RSPAMD_MAPS . '/' . self::LOOKALIKE_MAP . '.map';
        return <<<LUA
local devcon_lua_maps = require "lua_maps"
local devcon_vip = devcon_lua_maps.map_add_from_ucl("{$vip}", "set", "DEVCON VIP display names")
local devcon_pdom = devcon_lua_maps.map_add_from_ucl("{$dom}", "set", "DEVCON protected domains")
local devcon_exempt = devcon_lua_maps.map_add_from_ucl("{$exempt}", "set", "DEVCON exempt senders")

local function devcon_norm_name(s)
  if not s or s == "" then return nil end
  local n = tostring(s):gsub("[^%w]", ""):lower()
  if n == "" then return nil end
  return n
end

rspamd_config:register_symbol({
  name = "MAILSEC_CEO_SPOOF",
  type = "normal",
  score = 8.0,
  group = "mailsec",
  description = "Protected VIP display name used by a non-protected sender (CEO fraud)",
  callback = function(task)
    if not devcon_vip then return false end
    local from = task:get_from("mime")
    if not from or not from[1] then return false end
    from = from[1]
    local nname = devcon_norm_name(from.name)
    if not nname or not devcon_vip:get_key(nname) then return false end
    local addr = from.addr and tostring(from.addr):lower() or nil
    if addr and devcon_exempt and devcon_exempt:get_key(addr) then return false end
    local dom = from.domain and tostring(from.domain):lower() or (addr and addr:match("@([^@]+)\$"))
    if dom and devcon_pdom and devcon_pdom:get_key(dom) then return false end
    return true, 1.0, (from.name or "vip")
  end,
})

rspamd_config:register_symbol({
  name = "MAILSEC_INTERNAL_SPOOF",
  type = "normal",
  score = 9.0,
  group = "mailsec",
  description = "From uses a protected/internal domain but arrived externally and unauthenticated",
  callback = function(task)
    if not devcon_pdom then return false end
    local from = task:get_from("smtp") or task:get_from("mime")
    if not from or not from[1] then return false end
    from = from[1]
    local addr = from.addr and tostring(from.addr):lower() or nil
    local dom = from.domain and tostring(from.domain):lower() or (addr and addr:match("@([^@]+)\$"))
    if not dom or not devcon_pdom:get_key(dom) then return false end
    if addr and devcon_exempt and devcon_exempt:get_key(addr) then return false end
    if task:get_user() then return false end
    local ip = task:get_from_ip()
    if ip and ip:is_local() then return false end
    if task:has_symbol("R_DKIM_ALLOW") or task:has_symbol("DKIM_ALLOW") then return false end
    if task:has_symbol("DMARC_POLICY_ALLOW") then return false end
    return true, 1.0, dom
  end,
})

-- ===================================================================
-- Lookalike / typosquat / homoglyph / TLD-swap detection.
-- Compares every sender domain against the protected-domains list. The
-- list is loaded via a callback map so it auto-refreshes when the map
-- file changes (no rspamd reload needed). Fail-open: score only.
-- ===================================================================
local devcon_pdom_list = {}
rspamd_config:add_map({
  url = "{$dom}",
  description = "DEVCON protected domains (lookalike source)",
  type = "callback",
  callback = function(data)
    local t = {}
    for line in tostring(data):gmatch("[^\\r\\n]+") do
      local v = line:gsub("%s+", ""):lower()
      if v ~= "" and v:sub(1, 1) ~= "#" then t[#t + 1] = v end
    end
    devcon_pdom_list = t
  end,
})

-- Live lookalike config (enabled + sensitivity). Watched map => panel toggles
-- apply without a reload. Defaults stay safe if the file is empty/missing.
local devcon_la_enabled = true
local devcon_la_sens = "medium"
rspamd_config:add_map({
  url = "{$look}",
  description = "DEVCON lookalike settings",
  type = "callback",
  callback = function(data)
    local en, sn = true, "medium"
    for line in tostring(data):gmatch("[^\\r\\n]+") do
      local k, v = line:match("^%s*([%w_]+)%s*=%s*(%S+)")
      if k == "enabled" then
        en = (v == "1" or v == "true" or v == "yes" or v == "on")
      elseif k == "sensitivity" then
        local lv = tostring(v):lower()
        if lv == "low" or lv == "medium" or lv == "high" then sn = lv end
      end
    end
    devcon_la_enabled = en
    devcon_la_sens = sn
  end,
})

-- Sensitivity profiles. Homoglyph + TLD-swap are always on (high confidence);
-- min_core gates them, typo/combo are the looser heuristics.
local devcon_la_profiles = {
  low    = { min_core = 4, typo = false, typo_fold = 99, typo_dist = 0, combo = false, combo_min = 99 },
  medium = { min_core = 4, typo = true,  typo_fold = 6,  typo_dist = 1, combo = true,  combo_min = 6 },
  high   = { min_core = 3, typo = true,  typo_fold = 5,  typo_dist = 2, combo = true,  combo_min = 5 },
}

-- Minimal public-suffix awareness for the 2-level TLDs our domains use.
local devcon_2level = {
  ["co.uk"] = true, ["org.uk"] = true, ["ac.uk"] = true, ["gov.uk"] = true, ["me.uk"] = true,
  ["co.hu"] = true, ["com.hu"] = true, ["org.hu"] = true, ["co.rs"] = true, ["com.de"] = true,
}

-- Fold common digit/letter confusables and drop separators so visually
-- similar cores compare equal (devc0n1 ~ devconl, dev-con ~ devcon).
local function devcon_fold(s)
  if not s or s == "" then return "" end
  local map = { ["0"] = "o", ["1"] = "l", ["3"] = "e", ["4"] = "a", ["5"] = "s", ["7"] = "t", ["8"] = "b" }
  s = tostring(s):lower():gsub("%d", function(d) return map[d] or d end)
  return (s:gsub("[^a-z0-9]", ""))
end

-- Return (core label, tld) honouring the small 2-level suffix set above.
local function devcon_split(domain)
  domain = tostring(domain):lower()
  if domain:sub(-1) == "." then domain = domain:sub(1, -2) end
  local labels = {}
  for l in domain:gmatch("[^%.]+") do labels[#labels + 1] = l end
  local n = #labels
  if n < 2 then return domain, "" end
  local tld2 = labels[n - 1] .. "." .. labels[n]
  if devcon_2level[tld2] and n >= 3 then
    return labels[n - 2], tld2
  end
  return labels[n - 1], labels[n]
end

-- Bounded Levenshtein: returns maxd+1 as soon as it is exceeded.
local function devcon_lev(a, b, maxd)
  local la, lb = #a, #b
  if math.abs(la - lb) > maxd then return maxd + 1 end
  if la == 0 then return lb end
  if lb == 0 then return la end
  local prev = {}
  for j = 0, lb do prev[j] = j end
  for i = 1, la do
    local cur = { [0] = i }
    local best = i
    local ca = a:byte(i)
    for j = 1, lb do
      local cost = (ca == b:byte(j)) and 0 or 1
      local v = math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost)
      cur[j] = v
      if v < best then best = v end
    end
    if best > maxd then return maxd + 1 end
    prev = cur
  end
  return prev[lb]
end

rspamd_config:register_symbol({
  name = "MAILSEC_LOOKALIKE_DOMAIN",
  type = "normal",
  score = 7.0,
  group = "mailsec",
  description = "Sender domain mimics a protected domain (typo / homoglyph / combosquat / TLD swap)",
  callback = function(task)
    if not devcon_la_enabled then return false end
    if #devcon_pdom_list == 0 then return false end
    local prof = devcon_la_profiles[devcon_la_sens] or devcon_la_profiles.medium

    local doms, seen = {}, {}
    local mf = task:get_from("mime")
    local sf = task:get_from("smtp")
    local function add_dom(d)
      if d then
        d = tostring(d):lower()
        if d ~= "" and not seen[d] then seen[d] = true; doms[#doms + 1] = d end
      end
    end
    if mf and mf[1] then add_dom(mf[1].domain) end
    if sf and sf[1] then add_dom(sf[1].domain) end
    if #doms == 0 then return false end

    local exempt_addr = nil
    if mf and mf[1] and mf[1].addr then exempt_addr = tostring(mf[1].addr):lower() end

    for _, d in ipairs(doms) do
      local skip = false
      if devcon_pdom and devcon_pdom:get_key(d) then skip = true end
      if not skip and devcon_exempt and exempt_addr and devcon_exempt:get_key(exempt_addr) then skip = true end
      if not skip then
        for _, p in ipairs(devcon_pdom_list) do
          if d ~= p and d:sub(-(#p + 1)) == ("." .. p) then skip = true; break end
        end
      end
      if not skip then
        local dcore, dtld = devcon_split(d)
        local dfold = devcon_fold(dcore)
        for _, p in ipairs(devcon_pdom_list) do
          if p ~= d then
            local pcore, ptld = devcon_split(p)
            if #pcore >= prof.min_core then
              local pfold = devcon_fold(pcore)
              local hit, mult = false, 0.7
              if dfold == pfold and dcore ~= pcore then
                hit, mult = true, 1.0          -- confusable/homoglyph of the core
              elseif dcore == pcore and dtld ~= ptld then
                hit, mult = true, 1.0          -- same core, different TLD (TLD swap)
              elseif prof.typo and #pfold >= prof.typo_fold and dfold ~= pfold
                  and devcon_lev(dfold, pfold, prof.typo_dist) <= prof.typo_dist then
                hit, mult = true, 0.7          -- character-level typo
              elseif prof.combo and #pcore >= prof.combo_min then
                local hay = "-" .. d:gsub("%.", "-") .. "-"
                if hay:find("-" .. pcore .. "-", 1, true) then
                  hit, mult = true, 0.7        -- brand used as a label (combosquat)
                end
              end
              if hit then return true, mult, (pcore .. "." .. ptld .. "~" .. d) end
            end
          end
        end
      end
    end
    return false
  end,
})
LUA;
    }

    /**
     * Deploy/refresh the anti-spoofing Lua rule as a managed marked block inside
     * rspamd.local.lua, ensuring the maps exist first. Validated with configtest
     * and rolled back on failure so a slip can never stop the engine.
     */
    private function ensureImpersonationLua(string $actor): array
    {
        // Ensure the maps exist (empty is valid) before the Lua references them.
        $this->ensureDir(self::RSPAMD_MAPS);
        foreach ([self::IMP_MAP_VIP, self::IMP_MAP_DOMAINS, self::IMP_MAP_EXEMPT] as $map) {
            $path = self::RSPAMD_MAPS . '/' . $map . '.map';
            if (!file_exists($path)) {
                file_put_contents($path, "# Managed by DEVCON Mail Security. Do not edit by hand.\n");
                @chmod($path, 0644);
            }
        }
        // Lookalike config map must exist (with defaults) before the Lua that
        // references it is validated by configtest.
        $lookPath = self::RSPAMD_MAPS . '/' . self::LOOKALIKE_MAP . '.map';
        if (!file_exists($lookPath)) {
            file_put_contents($lookPath, $this->lookalikeConfigContent([]));
            @chmod($lookPath, 0644);
        }

        $block = self::IMP_LUA_BEGIN . "\n" . $this->renderImpersonationLua() . "\n" . self::IMP_LUA_END;
        $original = file_exists(self::LUA_LOCAL) ? (string) @file_get_contents(self::LUA_LOCAL) : '';

        if (str_contains($original, self::IMP_LUA_BEGIN)) {
            $pattern = '/' . preg_quote(self::IMP_LUA_BEGIN, '/') . '.*?' . preg_quote(self::IMP_LUA_END, '/') . '/s';
            $content = preg_replace($pattern, $block, $original, 1);
            if ($content === null) {
                return ['success' => false, 'error' => 'failed to update impersonation lua block'];
            }
        } else {
            $content = ($original === '' ? '' : rtrim($original) . "\n\n") . $block . "\n";
        }

        if ($content !== $original) {
            if (file_exists(self::LUA_LOCAL)) {
                $this->backupFile(self::LUA_LOCAL, 'install', $actor);
            }
            if (file_put_contents(self::LUA_LOCAL, $content) === false) {
                return ['success' => false, 'error' => 'failed to write rspamd.local.lua'];
            }
            @chmod(self::LUA_LOCAL, 0644);
        }

        $test = $this->execCommand('rspamadm', ['configtest'], 30);
        if (!($test['success'] ?? false)) {
            if ($original === '') {
                @unlink(self::LUA_LOCAL);
            } else {
                @file_put_contents(self::LUA_LOCAL, $original);
            }
            return ['success' => false, 'error' => 'configtest rejected impersonation lua: ' . trim((string) ($test['output'] ?? ''))];
        }

        return ['success' => true];
    }

    // ==================== MAIL FLOW RULES ENGINE ====================

    /**
     * Write the rules payload (enforcement mode + ordered rule list) as JSON to a
     * watched map, so the Lua postfilter picks up changes live (no reload).
     */
    private function writeRulesMap(array $rules, string $actor, array &$written): void
    {
        $this->ensureDir(self::RSPAMD_MAPS);
        $payload = [
            'mode' => (($rules['mode'] ?? 'monitor') === 'active') ? 'active' : 'monitor',
            'rules' => array_values(array_filter((array) ($rules['rules'] ?? []), 'is_array')),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{"mode":"monitor","rules":[]}';
        }
        $path = self::RSPAMD_MAPS . '/' . self::RULES_MAP . '.map';
        if (file_exists($path)) {
            $this->backupFile($path, 'exportMaps', $actor);
        }
        file_put_contents($path, $json . "\n");
        @chmod($path, 0644);
        $written[self::RULES_MAP] = count($payload['rules']);
    }

    /**
     * Static Lua postfilter that interprets the watched rules map. Conditions in a
     * rule are AND-ed; rules run in priority order and the first match wins. In
     * 'monitor' mode enforcing actions are downgraded to a marker header so nothing
     * is blocked; in 'active' mode they enforce. Fail-open by construction.
     */
    private function renderRulesLua(): string
    {
        $rulesPath = self::RSPAMD_MAPS . '/' . self::RULES_MAP . '.map';
        $quarHeader = self::QUARANTINE_HEADER;
        return <<<LUA
local devcon_rx = require "rspamd_regexp"
local devcon_ucl = require "ucl"

local devcon_rules = { mode = "monitor", list = {} }

-- UCL may collapse a single-element JSON array into a bare object; normalize so
-- one-rule / one-condition payloads still iterate correctly.
local function devcon_as_array(v)
  if type(v) ~= "table" then return {} end
  if v[1] ~= nil then return v end
  if v.action ~= nil or v.field ~= nil or v.name ~= nil then return { v } end
  return v
end

rspamd_config:add_map({
  url = "{$rulesPath}",
  description = "DEVCON mail flow rules",
  type = "callback",
  callback = function(data)
    local parser = devcon_ucl.parser()
    local ok = parser:parse_string(tostring(data))
    if not ok then return end
    local obj = parser:get_object()
    if type(obj) ~= "table" then return end
    devcon_rules = {
      mode = (obj.mode == "active") and "active" or "monitor",
      list = devcon_as_array(obj.rules),
    }
  end,
})

local function devcon_rx_get(pat)
  if not pat or pat == "" then return nil end
  return devcon_rx.create_cached(pat)
end

local function devcon_str_match(hay, op, val)
  if hay == nil then return false end
  hay = tostring(hay)
  if op == "regex" then
    local re = devcon_rx_get(val)
    return re ~= nil and re:match(hay) ~= nil
  end
  local h = hay:lower()
  local v = tostring(val):lower()
  if op == "equals" then return h == v end
  if op == "contains" then return h:find(v, 1, true) ~= nil end
  return false
end

local function devcon_addr_match(addrs, op, val)
  if not addrs then return false end
  local v = tostring(val):lower()
  local re = (op == "regex") and devcon_rx_get(val) or nil
  for _, a in ipairs(addrs) do
    local addr = a.addr and tostring(a.addr):lower() or ""
    local dom = a.domain and tostring(a.domain):lower() or ""
    if op == "domain_is" then
      if dom == v then return true end
    elseif op == "equals" then
      if addr == v then return true end
    elseif op == "contains" then
      if addr:find(v, 1, true) then return true end
    elseif re then
      if re:match(addr) or (dom ~= "" and re:match(dom)) then return true end
    end
  end
  return false
end

local function devcon_cond_match(task, c)
  local field, op, val = c.field, c.op, c.value
  if field == "from" then
    if devcon_addr_match(task:get_from("smtp"), op, val) then return true end
    return devcon_addr_match(task:get_from("mime"), op, val)
  elseif field == "to" then
    if devcon_addr_match(task:get_recipients("smtp"), op, val) then return true end
    return devcon_addr_match(task:get_recipients("mime"), op, val)
  elseif field == "subject" then
    return devcon_str_match(task:get_subject(), op, val)
  elseif field == "header" then
    local hv = task:get_header_full(c.name)
    if op == "exists" then return hv ~= nil and #hv > 0 end
    if not hv then return false end
    for _, h in ipairs(hv) do
      if devcon_str_match(h.decoded or h.value, op, val) then return true end
    end
    return false
  elseif field == "score" then
    local s = task:get_metric_score("default")
    local sc = (type(s) == "table") and s[1] or s
    sc = tonumber(sc)
    return sc ~= nil and sc >= (tonumber(val) or 0)
  elseif field == "symbol" then
    return task:has_symbol(tostring(val)) == true
  elseif field == "size" then
    return (task:get_size() or 0) >= (tonumber(val) or 0)
  elseif field == "attachment" then
    local parts = task:get_parts() or {}
    for _, p in ipairs(parts) do
      local fname = p:get_filename()
      if fname then
        fname = tostring(fname):lower()
        if op == "ext" then
          local want = tostring(val):lower():gsub("^%.", "")
          if want ~= "" and fname:sub(-(#want + 1)) == ("." .. want) then return true end
        elseif op == "regex" then
          local re = devcon_rx_get(val)
          if re and re:match(fname) then return true end
        end
      end
    end
    return false
  end
  return false
end

local function devcon_rule_matches(task, r)
  local conds = devcon_as_array(r.conditions)
  if #conds == 0 then return true end
  for _, c in ipairs(conds) do
    if not devcon_cond_match(task, c) then return false end
  end
  return true
end

local function devcon_add_header(task, name, value)
  task:set_milter_reply({ add_headers = { [name] = { value = value, order = 1 } } })
end

local function devcon_apply(task, r, mode)
  local action = r.action
  local arg = r.arg or ""

  if action == "tag" then
    local name, value = arg:match("^%s*([%w%-]+)%s*:%s*(.+)%s*\$")
    if name then devcon_add_header(task, name, value)
    else devcon_add_header(task, "X-Devcon-Rule", r.name or "matched") end
    return
  end

  if mode ~= "active" then
    devcon_add_header(task, "X-Devcon-Rule-Monitor", action .. ":" .. (r.name or ""))
    return
  end

  if action == "reject" then
    task:set_pre_result("reject", (arg ~= "" and arg) or "Message rejected by mail flow rule", "mailsec_rules")
  elseif action == "quarantine" then
    -- Stamp the routing header (Postfix header_checks holds it) and flag the
    -- action so it is not treated as clean. Explicit header = robust regardless
    -- of milter_headers timing.
    devcon_add_header(task, "{$quarHeader}", "yes")
    task:set_pre_result("add header", "Held by mail flow rule", "mailsec_rules")
  elseif action == "delete" then
    -- 'discard' is a milter action that loses priority ties to greylist, so pass
    -- an explicit high pre-result priority (5th arg) so a delete rule is honored.
    task:set_pre_result("discard", "Discarded by mail flow rule", "mailsec_rules", nil, 10)
  elseif action == "move" then
    devcon_add_header(task, "X-Spam-Flag", "YES")
  end
end

rspamd_config:register_symbol({
  name = "MAILSEC_RULES",
  type = "postfilter",
  priority = 10,
  group = "mailsec",
  callback = function(task)
    local rules = devcon_rules.list
    if not rules or #rules == 0 then return end
    for _, r in ipairs(rules) do
      if devcon_rule_matches(task, r) then
        task:insert_result("MAILSEC_RULES", 0.0, (r.name or "rule") .. "/" .. (r.action or "?"))
        devcon_apply(task, r, devcon_rules.mode)
        return
      end
    end
  end,
})
LUA;
    }

    /**
     * Deploy/refresh the rules-engine Lua as a managed marked block, ensuring the
     * watched map exists first. configtest-gated and rolled back on failure.
     */
    private function ensureRulesLua(string $actor): array
    {
        $this->ensureDir(self::RSPAMD_MAPS);
        $path = self::RSPAMD_MAPS . '/' . self::RULES_MAP . '.map';
        if (!file_exists($path)) {
            file_put_contents($path, "{\"mode\":\"monitor\",\"rules\":[]}\n");
            @chmod($path, 0644);
        }

        $block = self::RULES_LUA_BEGIN . "\n" . $this->renderRulesLua() . "\n" . self::RULES_LUA_END;
        $original = file_exists(self::LUA_LOCAL) ? (string) @file_get_contents(self::LUA_LOCAL) : '';

        if (str_contains($original, self::RULES_LUA_BEGIN)) {
            $pattern = '/' . preg_quote(self::RULES_LUA_BEGIN, '/') . '.*?' . preg_quote(self::RULES_LUA_END, '/') . '/s';
            $content = preg_replace($pattern, $block, $original, 1);
            if ($content === null) {
                return ['success' => false, 'error' => 'failed to update rules lua block'];
            }
        } else {
            $content = ($original === '' ? '' : rtrim($original) . "\n\n") . $block . "\n";
        }

        if ($content !== $original) {
            if (file_exists(self::LUA_LOCAL)) {
                $this->backupFile(self::LUA_LOCAL, 'install', $actor);
            }
            if (file_put_contents(self::LUA_LOCAL, $content) === false) {
                return ['success' => false, 'error' => 'failed to write rspamd.local.lua'];
            }
            @chmod(self::LUA_LOCAL, 0644);
        }

        $test = $this->execCommand('rspamadm', ['configtest'], 30);
        if (!($test['success'] ?? false)) {
            if ($original === '') {
                @unlink(self::LUA_LOCAL);
            } else {
                @file_put_contents(self::LUA_LOCAL, $original);
            }
            return ['success' => false, 'error' => 'configtest rejected rules lua: ' . trim((string) ($test['output'] ?? ''))];
        }

        return ['success' => true];
    }

    // ==================== GEO-IP COUNTRY FILTERING ====================

    /**
     * Write the Geo-IP payload (enforcement mode + global default + per-domain
     * overrides) as JSON to a watched map, so the Lua postfilter picks up changes
     * live (no reload). Country codes are arrays; domains is an object so it stays
     * a JSON object even when empty.
     */
    private function writeGeoipMap(array $geoip, string $actor, array &$written): void
    {
        $this->ensureDir(self::RSPAMD_MAPS);

        $domains = [];
        if (isset($geoip['domains']) && is_array($geoip['domains'])) {
            foreach ($geoip['domains'] as $dom => $pol) {
                if (is_array($pol) && $dom !== '') {
                    $domains[(string) $dom] = $pol;
                }
            }
        }

        $payload = [
            'mode'    => (($geoip['mode'] ?? 'monitor') === 'active') ? 'active' : 'monitor',
            'enabled' => !empty($geoip['enabled']),
            'default' => is_array($geoip['default'] ?? null)
                ? $geoip['default']
                : ['mode' => 'deny', 'countries' => [], 'action' => 'reject'],
            'domains' => (object) $domains,
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $json = '{"mode":"monitor","enabled":false,"default":{"mode":"deny","countries":[],"action":"reject"},"domains":{}}';
        }

        $path = self::RSPAMD_MAPS . '/' . self::GEOIP_MAP . '.map';
        if (file_exists($path)) {
            $this->backupFile($path, 'exportMaps', $actor);
        }
        file_put_contents($path, $json . "\n");
        @chmod($path, 0644);
        $written[self::GEOIP_MAP] = count($domains) + (!empty($geoip['enabled']) ? 1 : 0);
    }

    /**
     * Static Lua postfilter that interprets the watched Geo-IP map. The sender
     * country is resolved by the ASN module (DNS-based) and read from the mempool.
     * A per-recipient-domain override wins over the global default. 'deny' blocks
     * listed countries; 'allow' blocks everything not listed. Unknown country
     * (e.g. no resolvable IP / internal mail) never blocks - fail open. In
     * 'monitor' mode enforcing actions are downgraded to a marker header.
     */
    private function renderGeoipLua(): string
    {
        $geoipPath = self::RSPAMD_MAPS . '/' . self::GEOIP_MAP . '.map';
        $quarHeader = self::QUARANTINE_HEADER;
        return <<<LUA
local devcon_geoip_ucl = require "ucl"

local devcon_geoip = { mode = "monitor", enabled = false, default = nil, domains = {} }

-- Build an upper-cased lookup set from a UCL value that may be a single string
-- (UCL collapses 1-element arrays), an array of strings, or nil.
local function devcon_geoip_set(v)
  local set = {}
  if type(v) == "string" then
    if v ~= "" then set[v:upper()] = true end
  elseif type(v) == "table" then
    for _, c in ipairs(v) do
      if type(c) == "string" and c ~= "" then set[c:upper()] = true end
    end
  end
  return set
end

local function devcon_geoip_policy(obj)
  if type(obj) ~= "table" then return nil end
  local mode = (obj.mode == "allow") and "allow" or "deny"
  local action = obj.action
  if action ~= "quarantine" and action ~= "tag" then action = "reject" end
  return { mode = mode, set = devcon_geoip_set(obj.countries), action = action }
end

rspamd_config:add_map({
  url = "{$geoipPath}",
  description = "DEVCON geoip policy",
  type = "callback",
  callback = function(data)
    local parser = devcon_geoip_ucl.parser()
    local ok = parser:parse_string(tostring(data))
    if not ok then return end
    local obj = parser:get_object()
    if type(obj) ~= "table" then return end
    local domains = {}
    if type(obj.domains) == "table" then
      for dom, pol in pairs(obj.domains) do
        if type(dom) == "string" then
          local p = devcon_geoip_policy(pol)
          if p then domains[dom:lower()] = p end
        end
      end
    end
    local en = obj.enabled
    devcon_geoip = {
      mode = (obj.mode == "active") and "active" or "monitor",
      enabled = (en == true) or (en == 1) or (en == "1") or (en == "true"),
      default = devcon_geoip_policy(obj.default),
      domains = domains,
    }
  end,
})

local function devcon_geoip_add_header(task, name, value)
  task:set_milter_reply({ add_headers = { [name] = { value = value, order = 1 } } })
end

-- Effective policy for a recipient domain: its override if present, else the
-- global default (only when global filtering is enabled).
local function devcon_geoip_policy_for(dom)
  if dom and devcon_geoip.domains[dom] then return devcon_geoip.domains[dom] end
  if devcon_geoip.enabled and devcon_geoip.default then return devcon_geoip.default end
  return nil
end

local function devcon_geoip_blocks(p, cc)
  if not p then return false end
  if cc == nil or cc == "" then return false end
  cc = tostring(cc):upper()
  if p.mode == "deny" then
    return p.set[cc] == true
  else
    return p.set[cc] ~= true
  end
end

local function devcon_geoip_apply(task, p, cc, dom)
  task:insert_result("MAILSEC_GEOIP", 0.0, tostring(cc or "??") .. "/" .. (p.mode or "deny") .. "/" .. tostring(dom or "?"))
  if p.action == "tag" then
    devcon_geoip_add_header(task, "X-Devcon-Geoip", tostring(cc or "??"))
    return
  end
  if devcon_geoip.mode ~= "active" then
    devcon_geoip_add_header(task, "X-Devcon-Geoip-Monitor", (p.action or "reject") .. ":" .. tostring(cc or "??"))
    return
  end
  if p.action == "reject" then
    task:set_pre_result("reject", "Message rejected by Geo-IP policy", "mailsec_geoip")
  elseif p.action == "quarantine" then
    devcon_geoip_add_header(task, "{$quarHeader}", "yes")
    task:set_pre_result("add header", "Held by Geo-IP policy", "mailsec_geoip")
  end
end

rspamd_config:register_symbol({
  name = "MAILSEC_GEOIP",
  type = "postfilter",
  priority = 9,
  group = "mailsec",
  callback = function(task)
    local cc = task:get_mempool():get_variable("country")
    local rcpts = task:get_recipients("smtp") or task:get_recipients("mime") or {}
    local seen = {}
    for _, r in ipairs(rcpts) do
      local dom = r.domain and tostring(r.domain):lower() or nil
      if dom and not seen[dom] then
        seen[dom] = true
        local p = devcon_geoip_policy_for(dom)
        if devcon_geoip_blocks(p, cc) then
          devcon_geoip_apply(task, p, cc, dom)
          return
        end
      end
    end
  end,
})
LUA;
    }

    /**
     * Deploy/refresh the Geo-IP Lua as a managed marked block, ensuring the
     * watched map exists first. configtest-gated and rolled back on failure.
     */
    private function ensureGeoipLua(string $actor): array
    {
        $this->ensureDir(self::RSPAMD_MAPS);
        $path = self::RSPAMD_MAPS . '/' . self::GEOIP_MAP . '.map';
        if (!file_exists($path)) {
            file_put_contents($path, "{\"mode\":\"monitor\",\"enabled\":false,\"default\":{\"mode\":\"deny\",\"countries\":[],\"action\":\"reject\"},\"domains\":{}}\n");
            @chmod($path, 0644);
        }

        $block = self::GEOIP_LUA_BEGIN . "\n" . $this->renderGeoipLua() . "\n" . self::GEOIP_LUA_END;
        $original = file_exists(self::LUA_LOCAL) ? (string) @file_get_contents(self::LUA_LOCAL) : '';

        if (str_contains($original, self::GEOIP_LUA_BEGIN)) {
            $pattern = '/' . preg_quote(self::GEOIP_LUA_BEGIN, '/') . '.*?' . preg_quote(self::GEOIP_LUA_END, '/') . '/s';
            $content = preg_replace($pattern, $block, $original, 1);
            if ($content === null) {
                return ['success' => false, 'error' => 'failed to update geoip lua block'];
            }
        } else {
            $content = ($original === '' ? '' : rtrim($original) . "\n\n") . $block . "\n";
        }

        if ($content !== $original) {
            if (file_exists(self::LUA_LOCAL)) {
                $this->backupFile(self::LUA_LOCAL, 'install', $actor);
            }
            if (file_put_contents(self::LUA_LOCAL, $content) === false) {
                return ['success' => false, 'error' => 'failed to write rspamd.local.lua'];
            }
            @chmod(self::LUA_LOCAL, 0644);
        }

        $test = $this->execCommand('rspamadm', ['configtest'], 30);
        if (!($test['success'] ?? false)) {
            if ($original === '') {
                @unlink(self::LUA_LOCAL);
            } else {
                @file_put_contents(self::LUA_LOCAL, $original);
            }
            return ['success' => false, 'error' => 'configtest rejected geoip lua: ' . trim((string) ($test['output'] ?? ''))];
        }

        return ['success' => true];
    }

    /**
     * Ensure Rspamd's ASN module is enabled so the sender country resolves (it
     * provides the 'country' mempool variable the Geo-IP postfilter reads, via a
     * DNS lookup against asn.rspamd.com - no local MaxMind DB needed).
     * configtest-gated and rolled back on failure.
     */
    private function ensureAsnModule(string $actor): array
    {
        $path = self::RSPAMD_LOCAL . '/asn.conf';
        $original = file_exists($path) ? (string) @file_get_contents($path) : null;
        $content = "# Managed by DEVCON Mail Security. Enables country/ASN resolution\n"
            . "# (DNS-based via asn.rspamd.com) consumed by Geo-IP filtering.\n"
            . "enabled = true;\n";

        if ($original === $content) {
            return ['success' => true];
        }
        if (!$this->writeConfig($path, $content, $actor)) {
            return ['success' => false, 'error' => 'failed to write asn.conf'];
        }

        $test = $this->execCommand('rspamadm', ['configtest'], 30);
        if (!($test['success'] ?? false)) {
            if ($original === null) {
                @unlink($path);
            } else {
                @file_put_contents($path, $original);
            }
            return ['success' => false, 'error' => 'configtest rejected asn.conf: ' . trim((string) ($test['output'] ?? ''))];
        }

        return ['success' => true];
    }

    // ==================== REACTIVE LEARNING LOOP (IMAPSieve) ====================

    /**
     * Enable or disable the IMAPSieve learning loop. When enabled, Dovecot fires
     * a sieve script on every COPY/APPEND into Junk and a learn_ham script on
     * every COPY out of Junk - regardless of whether the user is in webmail or a
     * native client. Both run through one allow-listed binary wrapper which
     * pipes the message to `rspamc learn_spam/learn_ham` and drops a JSON event
     * into a spool drained by the panel ingester.
     *
     * Params:
     *   enabled (bool, default true) - install (true) or remove (false) the hooks
     */
    protected function actionSetupLearning(array $params, string $actor): array
    {
        $enabled = array_key_exists('enabled', $params)
            ? filter_var($params['enabled'], FILTER_VALIDATE_BOOLEAN)
            : true;

        $result = $this->ensureLearningLoop($actor, $enabled);
        if (!($result['success'] ?? false)) {
            return $this->error($result['error'] ?? 'Failed to configure learning loop', $result);
        }

        return $this->success($this->learnStatusPayload(), $enabled
            ? "Learning loop enabled by {$actor}"
            : "Learning loop disabled by {$actor}");
    }

    /**
     * Report the live state of the IMAPSieve learning loop so the panel can
     * show whether the hooks are installed, the wrapper is in place, and the
     * Bayes classifier is reporting learned counts. Read-only.
     */
    protected function actionLearnStatus(array $params, string $actor): array
    {
        return $this->success($this->learnStatusPayload());
    }

    /**
     * Toggle Rspamd's score-threshold autolearn. When ON, Rspamd trains the
     * Bayes corpus from every message that scores deep in spam or ham
     * territory. When OFF, the corpus is fed exclusively by user feedback
     * (webmail buttons + the IMAPSieve loop). The conf is configtest-gated
     * + rolled back on failure so a bad value can never break the engine.
     *
     * Params: enabled (bool, default true)
     */
    protected function actionSetBayesAutolearn(array $params, string $actor): array
    {
        $enabled = array_key_exists('enabled', $params)
            ? filter_var($params['enabled'], FILTER_VALIDATE_BOOLEAN)
            : true;

        $path = self::RSPAMD_LOCAL . '/classifier-bayes.conf';
        $body = "backend = \"redis\";\nautolearn = " . ($enabled ? 'true' : 'false') . ";\n";

        $original = file_exists($path) ? (string) @file_get_contents($path) : null;
        if ($original === $body) {
            return $this->success(['autolearn' => $enabled], 'Bayes autolearn already ' . ($enabled ? 'enabled' : 'disabled'));
        }

        if (!$this->writeConfig($path, $body, $actor)) {
            return $this->error('Failed to write classifier-bayes.conf');
        }

        $check = $this->execCommand('rspamadm', ['configtest'], 30);
        if (!($check['success'] ?? false)) {
            if ($original === null) {
                @unlink($path);
            } else {
                @file_put_contents($path, $original);
            }
            return $this->error('Config rejected by rspamadm configtest (rolled back): ' . ($check['output'] ?? ''));
        }

        $this->execCommand('systemctl', ['reload', 'rspamd'], 30);

        return $this->success([
            'autolearn' => $enabled,
        ], 'Bayes autolearn ' . ($enabled ? 'enabled' : 'disabled') . " by {$actor}");
    }

    /**
     * Read the current Bayes autolearn flag from the rendered conf. Returns
     * true (default) if the file is missing or malformed so we present the
     * safest assumption to the UI.
     */
    private function readBayesAutolearn(): bool
    {
        $path = self::RSPAMD_LOCAL . '/classifier-bayes.conf';
        if (!file_exists($path)) {
            return true;
        }
        $body = (string) @file_get_contents($path);
        if (preg_match('/^\s*autolearn\s*=\s*(true|false)\s*;/mi', $body, $m)) {
            return strtolower($m[1]) === 'true';
        }
        return true;
    }

    /**
     * Snapshot of the learning loop's wiring + Bayes counts. The booleans tell
     * the panel whether the hooks are installed and the live IMAP plugin set
     * actually loads imap_sieve (which is how Dovecot picks them up).
     */
    private function learnStatusPayload(): array
    {
        // Parse the normalized config once. `doveconf -h <filter>` with a
        // space-joined arg is NOT valid filter syntax (it silently returns
        // nothing), so we dump `-n` and scan it - this also reflects exactly
        // what the running server loaded.
        $confDump = '';
        $res = $this->execCommand('doveconf', ['-n'], 10);
        if ($res['success'] ?? false) {
            $confDump = (string) ($res['output'] ?? '');
        }

        $imapPlugins = '';
        $sievePlugins = '';
        foreach (preg_split('/\r?\n/', $confDump) ?: [] as $line) {
            $t = trim($line);
            if ($imapPlugins === '' && str_starts_with($t, 'mail_plugins') && str_contains($t, 'imap_sieve')) {
                $imapPlugins = $t;
            }
            if ($sievePlugins === '' && str_starts_with($t, 'sieve_plugins')) {
                $sievePlugins = $t;
            }
        }
        $imapSieveLoaded = str_contains($confDump, 'imap_sieve');

        $learnedSpam  = null;
        $learnedHam   = null;
        $learnedTotal = null;
        $raw = @file_get_contents(self::CONTROLLER_URL . '/stat', false, stream_context_create([
            'http' => ['timeout' => 5],
        ]));
        if ($raw !== false) {
            $stat = json_decode($raw, true);
            if (is_array($stat)) {
                if (isset($stat['learned']) && is_numeric($stat['learned'])) {
                    $learnedTotal = (int) $stat['learned'];
                }
                if (isset($stat['learned_spam']) && is_numeric($stat['learned_spam'])) {
                    $learnedSpam = (int) $stat['learned_spam'];
                }
                if (isset($stat['learned_ham']) && is_numeric($stat['learned_ham'])) {
                    $learnedHam = (int) $stat['learned_ham'];
                }
                // Fall back to scanning Rspamd's per-classifier breakdown if the
                // top-level keys aren't present (Rspamd reports both shapes).
                if (($learnedSpam === null || $learnedHam === null) && isset($stat['statfiles']) && is_array($stat['statfiles'])) {
                    foreach ($stat['statfiles'] as $sf) {
                        if (!is_array($sf)) {
                            continue;
                        }
                        $type = strtolower((string) ($sf['symbol'] ?? $sf['type'] ?? ''));
                        $rev = (int) ($sf['revision'] ?? 0);
                        if ($learnedSpam === null && str_contains($type, 'spam')) {
                            $learnedSpam = $rev;
                        }
                        if ($learnedHam === null && str_contains($type, 'ham')) {
                            $learnedHam = $rev;
                        }
                    }
                }
            }
        }

        return [
            'enabled' => file_exists(self::LEARN_DOVECOT_CONF),
            'wrapper_present' => file_exists(self::LEARN_PIPE_BIN),
            'sieve_spam_present' => file_exists(self::LEARN_SIEVE_SPAM),
            'sieve_ham_present' => file_exists(self::LEARN_SIEVE_HAM),
            'spool_present' => is_dir(self::LEARN_SPOOL_DIR),
            'spool_pending' => $this->countSpoolPending(),
            'imap_plugins' => $imapPlugins,
            'sieve_plugins' => $sievePlugins,
            'imap_sieve_loaded' => $imapSieveLoaded,
            'pigeonhole_present' => $this->commandExists('sievec'),
            'bayes' => [
                'learned_spam' => $learnedSpam,
                'learned_ham' => $learnedHam,
                'learned_total' => $learnedTotal,
                'autolearn' => $this->readBayesAutolearn(),
            ],
        ];
    }

    /**
     * Count un-drained events currently sitting in the spool. Best-effort; the
     * dashboard uses this to surface backlog (e.g. the ingester is broken).
     */
    private function countSpoolPending(): int
    {
        if (!is_dir(self::LEARN_SPOOL_DIR)) {
            return 0;
        }
        $files = @glob(rtrim(self::LEARN_SPOOL_DIR, '/') . '/*.json');
        return is_array($files) ? count($files) : 0;
    }

    /**
     * Idempotently install OR remove the IMAPSieve learning loop. Validated
     * with doveconf and rolled back on failure so a botched edit cannot break
     * Dovecot. Returns a structured result with 'success' + 'steps'.
     */
    private function ensureLearningLoop(string $actor, bool $enabled): array
    {
        if ($enabled) {
            return $this->installLearningLoop($actor);
        }
        return $this->uninstallLearningLoop($actor);
    }

    private function installLearningLoop(string $actor): array
    {
        $steps = [];

        if (!$this->commandExists('sievec')) {
            return ['success' => false, 'error' => 'pigeonhole (sievec) is not installed; install dovecot-sieve to enable the learning loop', 'steps' => $steps];
        }
        if (!file_exists(self::LEARN_WRAPPER_SRC)) {
            return ['success' => false, 'error' => 'learn wrapper source is missing on the agent', 'steps' => $steps];
        }

        // 1. Wrapper binary in an allow-listed pipe dir. Vmail will exec it, so
        //    it must be world-readable + executable.
        $this->ensureDir(self::LEARN_PIPE_DIR);
        @chmod(self::LEARN_PIPE_DIR, 0755);
        if (file_exists(self::LEARN_PIPE_BIN)) {
            $this->backupFile(self::LEARN_PIPE_BIN, 'setupLearning', $actor);
        }
        if (!copy(self::LEARN_WRAPPER_SRC, self::LEARN_PIPE_BIN)) {
            return ['success' => false, 'error' => 'failed to deploy learn wrapper binary', 'steps' => $steps];
        }
        @chmod(self::LEARN_PIPE_BIN, 0755);
        $steps['wrapper'] = true;

        // 2. Spool dir: vmail (the wrapper) writes; www-data (the ingester)
        //    drains. setgid bit + group-write so both can co-operate.
        $this->ensureDir(self::LEARN_SPOOL_DIR);
        $this->execCommand('chown', ['vmail:www-data', self::LEARN_SPOOL_DIR], 10);
        @chmod(self::LEARN_SPOOL_DIR, 02775);
        $steps['spool'] = is_dir(self::LEARN_SPOOL_DIR);

        // 3. Opt-out file - empty initially. The event-sync ingester rewrites it
        //    every minute from MailFlow's webmail_spam_settings so the existing
        //    per-user "Spam Filter Training" toggle governs IMAP feedback too.
        $this->ensureDir(dirname(self::LEARN_OPTOUT_FILE));
        if (!file_exists(self::LEARN_OPTOUT_FILE)) {
            @file_put_contents(
                self::LEARN_OPTOUT_FILE,
                "# Managed by DEVCON Mail Security. Users in this list have webmail\n"
                . "# spam-filter training disabled; their IMAP Junk drags will not\n"
                . "# train Rspamd. Rewritten every minute by mailsec-event-sync.\n"
            );
            @chmod(self::LEARN_OPTOUT_FILE, 0644);
        }
        $steps['optout'] = file_exists(self::LEARN_OPTOUT_FILE);

        // 4. IMAPSieve script SOURCES that pipe to the wrapper. The :copy flag
        //    keeps the message in place; without it Dovecot would re-deliver it.
        //    We only write the source here - compilation is deferred to step 6,
        //    AFTER the Dovecot conf is in place, because these scripts require
        //    vnd.dovecot.pipe which sievec only resolves once sieve_extprograms
        //    is loaded via that conf (chicken-and-egg otherwise).
        $this->ensureDir(self::LEARN_SIEVE_DIR);
        // Dovecot compiles these IMAPSieve before-scripts at RUNTIME (standalone
        // sievec can't resolve the imapsieve extension, which only exists inside
        // the live imap process) and caches the .svbin next to the source. The
        // imap process runs as the mail user (vmail), so the dir must be group-
        // writable by vmail or every Junk move logs "Permission denied" and
        // recompiles from scratch. root:vmail + setgid keeps cached binaries
        // group-owned by vmail. Best-effort: harmless if the group is absent.
        $this->execCommand('chown', ['root:vmail', self::LEARN_SIEVE_DIR], 10);
        @chmod(self::LEARN_SIEVE_DIR, 02775);

        $sieveSpam = $this->renderLearnSieve('spam');
        $sieveHam  = $this->renderLearnSieve('ham');

        $needCompile = [];
        $writeSieveSource = function (string $path, string $body) use ($actor, &$needCompile): bool {
            $changed = (@file_get_contents($path) !== $body);
            if ($changed) {
                if (file_exists($path)) {
                    $this->backupFile($path, 'setupLearning', $actor);
                }
                if (file_put_contents($path, $body) === false) {
                    return false;
                }
                @chmod($path, 0644);
            }
            if ($changed || !file_exists($path . '.svbin')) {
                $needCompile[$path] = true;
            }
            return true;
        };

        if (!$writeSieveSource(self::LEARN_SIEVE_SPAM, $sieveSpam)) {
            return ['success' => false, 'error' => 'failed to write learn-spam sieve script', 'steps' => $steps];
        }
        if (!$writeSieveSource(self::LEARN_SIEVE_HAM, $sieveHam)) {
            return ['success' => false, 'error' => 'failed to write learn-ham sieve script', 'steps' => $steps];
        }
        $steps['sieve_scripts'] = true;

        // 5. Dovecot conf. Backed up + rolled back on doveconf failure so a
        //    bad edit cannot ever break IMAP delivery. Written BEFORE the sieve
        //    scripts are compiled so sievec can resolve vnd.dovecot.pipe.
        $conf = $this->renderLearnDovecotConf();
        $originalConf = file_exists(self::LEARN_DOVECOT_CONF)
            ? (string) @file_get_contents(self::LEARN_DOVECOT_CONF)
            : null;

        if ($originalConf !== $conf) {
            if ($originalConf !== null) {
                $this->backupFile(self::LEARN_DOVECOT_CONF, 'setupLearning', $actor);
            }
            if (file_put_contents(self::LEARN_DOVECOT_CONF, $conf) === false) {
                return ['success' => false, 'error' => 'failed to write dovecot learn config', 'steps' => $steps];
            }
            @chmod(self::LEARN_DOVECOT_CONF, 0644);

            $check = $this->execCommand('doveconf', ['-n'], 30);
            if (!($check['success'] ?? false)) {
                if ($originalConf === null) {
                    @unlink(self::LEARN_DOVECOT_CONF);
                } else {
                    @file_put_contents(self::LEARN_DOVECOT_CONF, $originalConf);
                }
                return ['success' => false, 'error' => 'doveconf rejected learn config: ' . ($check['output'] ?? ''), 'steps' => $steps];
            }
        }
        $steps['dovecot_conf'] = true;

        // 5b. Make sure Dovecot actually LOADS the conf. Monolithic installs
        //     never glob conf.d, so the file would sit there inert. We detect
        //     this and, if needed, add a validated !include to the main config.
        //     Done before compiling so sievec also sees sieve_extprograms.
        $load = $this->ensureLearnConfLoaded($actor);
        $steps['dovecot_loaded'] = $load['loaded'] ?? false;
        if (!empty($load['method'])) {
            $steps['dovecot_load_method'] = $load['method'];
        }
        if (empty($load['loaded'])) {
            return ['success' => false, 'error' => $load['error'] ?? 'dovecot is not loading the learn config', 'steps' => $steps];
        }

        // 6. Compile the sieve scripts now that sieve_extprograms is enabled.
        //    Non-fatal: if sievec still fails, Dovecot compiles the before-script
        //    on first use (in the full plugin context) and the loop still works -
        //    we just lose the pre-cached .svbin. So we never abort the install here.
        $compileWarnings = [];
        foreach (array_keys($needCompile) as $path) {
            $compile = $this->execCommand('sievec', [$path], 30);
            if ($compile['success'] ?? false) {
                @chmod($path . '.svbin', 0644);
            } else {
                $compileWarnings[] = basename($path) . ': ' . trim((string) ($compile['output'] ?? ''));
            }
        }
        $steps['sieve_compiled'] = empty($compileWarnings);
        if (!empty($compileWarnings)) {
            $steps['sieve_compile_warnings'] = $compileWarnings;
        }

        $this->execCommand('systemctl', ['reload', 'dovecot'], 30);

        return ['success' => true, 'steps' => $steps];
    }

    private function uninstallLearningLoop(string $actor): array
    {
        $steps = [];

        // The conf is the linchpin - removing it disables the hooks. We back
        // it up so an admin can resurrect it manually if needed.
        if (file_exists(self::LEARN_DOVECOT_CONF)) {
            $this->backupFile(self::LEARN_DOVECOT_CONF, 'setupLearning', $actor);
            @unlink(self::LEARN_DOVECOT_CONF);
        }
        $steps['dovecot_conf'] = !file_exists(self::LEARN_DOVECOT_CONF);

        // Strip the managed !include from the main config if we added one, so a
        // monolithic install doesn't keep trying to load a now-deleted file.
        $steps['dovecot_include'] = $this->removeLearnConfInclude($actor);

        $check = $this->execCommand('doveconf', ['-n'], 30);
        if ($check['success'] ?? false) {
            $this->execCommand('systemctl', ['reload', 'dovecot'], 30);
        }

        // Leave the wrapper + sieve scripts + spool in place so a re-enable is
        // cheap and any in-flight events finish draining.
        return ['success' => true, 'steps' => $steps];
    }

    /** Marker line that brackets the !include we may add to the main config. */
    private const LEARN_INCLUDE_MARKER = '# DEVCON Mail Security: reactive-learning include (managed)';

    /**
     * Make sure Dovecot actually LOADS our learn conf. Many installs are
     * monolithic and never glob conf.d, so the file in conf.d is inert. We
     * detect this by checking for our marker in `doveconf -n`; if it's missing
     * we append an explicit, validated, backed-up !include to the main
     * dovecot.conf (at the end, so $mail_plugins is already defined). Rolled
     * back on failure so it can never break IMAP. Idempotent.
     */
    private function ensureLearnConfLoaded(string $actor): array
    {
        $dump = $this->execCommand('doveconf', ['-n'], 15);
        if (($dump['success'] ?? false) && str_contains((string) ($dump['output'] ?? ''), self::LEARN_LOAD_MARKER)) {
            return ['loaded' => true, 'method' => 'confd'];
        }

        if (!file_exists(self::LEARN_DOVECOT_MAIN)) {
            return ['loaded' => false, 'error' => 'dovecot main config not found at ' . self::LEARN_DOVECOT_MAIN];
        }

        $main = (string) @file_get_contents(self::LEARN_DOVECOT_MAIN);
        $include = "\n" . self::LEARN_INCLUDE_MARKER . "\n!include " . self::LEARN_DOVECOT_CONF . "\n";

        if (!str_contains($main, self::LEARN_INCLUDE_MARKER)) {
            $this->backupFile(self::LEARN_DOVECOT_MAIN, 'setupLearning', $actor);
            if (file_put_contents(self::LEARN_DOVECOT_MAIN, $main . $include) === false) {
                return ['loaded' => false, 'error' => 'failed to append !include to ' . self::LEARN_DOVECOT_MAIN];
            }
        }

        // Confirm the marker now actually appears in the parsed config (this also
        // catches a doveconf parse error introduced by the include).
        $recheck = $this->execCommand('doveconf', ['-n'], 15);
        $ok = ($recheck['success'] ?? false)
            && str_contains((string) ($recheck['output'] ?? ''), self::LEARN_LOAD_MARKER);
        if (!$ok) {
            @file_put_contents(self::LEARN_DOVECOT_MAIN, $main);
            return ['loaded' => false, 'error' => 'dovecot did not load learn conf after !include: ' . trim((string) ($recheck['output'] ?? ''))];
        }

        return ['loaded' => true, 'method' => 'include'];
    }

    /**
     * Remove the managed !include block (marker line + the following !include
     * line) from the main dovecot.conf. Best-effort + backed up. Returns true
     * if the file is left without our include.
     */
    private function removeLearnConfInclude(string $actor): bool
    {
        if (!file_exists(self::LEARN_DOVECOT_MAIN)) {
            return true;
        }
        $main = (string) @file_get_contents(self::LEARN_DOVECOT_MAIN);
        if (!str_contains($main, self::LEARN_INCLUDE_MARKER)) {
            return true;
        }

        $this->backupFile(self::LEARN_DOVECOT_MAIN, 'setupLearning', $actor);

        $lines = preg_split('/\r?\n/', $main) ?: [];
        $out = [];
        $skipNextInclude = false;
        foreach ($lines as $line) {
            if (trim($line) === self::LEARN_INCLUDE_MARKER) {
                $skipNextInclude = true;
                continue;
            }
            if ($skipNextInclude && str_starts_with(trim($line), '!include ' . self::LEARN_DOVECOT_CONF)) {
                $skipNextInclude = false;
                continue;
            }
            $skipNextInclude = false;
            $out[] = $line;
        }
        $rebuilt = rtrim(implode("\n", $out)) . "\n";

        if (file_put_contents(self::LEARN_DOVECOT_MAIN, $rebuilt) === false) {
            @file_put_contents(self::LEARN_DOVECOT_MAIN, $main);
            return false;
        }
        return true;
    }

    /**
     * Tiny IMAPSieve script: extracts the IMAP username from the sieve runtime
     * environment and pipes the message body to the learn wrapper with the
     * direction + username as argv. :copy keeps the message in place.
     */
    private function renderLearnSieve(string $direction): string
    {
        $direction = $direction === 'spam' ? 'spam' : 'ham';
        return "# Managed by DEVCON Mail Security. Do not edit by hand.\n"
            . "# Trains Rspamd's Bayes classifier when users move messages to/from Junk.\n"
            . "require [\"vnd.dovecot.pipe\", \"copy\", \"imapsieve\", \"environment\", \"variables\"];\n"
            . "\n"
            . "if environment :matches \"imap.user\" \"*\" {\n"
            . "    set \"username\" \"\${1}\";\n"
            . "}\n"
            . "\n"
            . "pipe :copy \"devcon-mailsec-learn\" [\"{$direction}\", \"\${username}\"];\n";
    }

    /**
     * Dovecot conf that loads imap_sieve, wires the learn scripts into the
     * Spam/Junk mailboxes, and points sieve_extprograms at our pipe-bin dir.
     *
     * Mailbox names: GFI-style mail clients diverge on whether the spam folder
     * is "Junk", "Spam", "INBOX.Junk" or "INBOX.Spam". The Dovecot ":matches"
     * pattern with sieve_extprograms requires literal names, so we register
     * every common variant. Sieve runs at most one before-script per move, so
     * multiple matches do not double-train.
     */
    private function renderLearnDovecotConf(): string
    {
        $pipeBin = self::LEARN_PIPE_DIR;
        $spam    = self::LEARN_SIEVE_SPAM;
        $ham     = self::LEARN_SIEVE_HAM;

        return "# Managed by DEVCON Mail Security. Do not edit by hand.\n"
            . "# Reactive Bayes learning: trains the classifier whenever a user\n"
            . "# drags a message into / out of Junk in ANY IMAP client.\n"
            . "\n"
            . "protocol imap {\n"
            . "  mail_plugins = \$mail_plugins imap_sieve\n"
            . "}\n"
            . "\n"
            . "plugin {\n"
            . "  sieve_plugins = sieve_imapsieve sieve_extprograms\n"
            . "\n"
            . "  # Allow-listed directory for sieve_pipe binaries.\n"
            . "  sieve_pipe_bin_dir = {$pipeBin}\n"
            . "\n"
            . "  # IMAPSieve before-scripts run as GLOBAL scripts; the non-standard\n"
            . "  # vnd.dovecot.pipe extension must be allow-listed here or Dovecot\n"
            . "  # refuses it (even at runtime). Required for the pipe to the wrapper.\n"
            . "  sieve_global_extensions = +vnd.dovecot.pipe\n"
            . "\n"
            . "  # Junk gains: drop into Spam/Junk -> learn as spam.\n"
            . "  imapsieve_mailbox1_name = Junk\n"
            . "  imapsieve_mailbox1_causes = COPY APPEND\n"
            . "  imapsieve_mailbox1_before = file:{$spam}\n"
            . "\n"
            . "  imapsieve_mailbox2_name = Spam\n"
            . "  imapsieve_mailbox2_causes = COPY APPEND\n"
            . "  imapsieve_mailbox2_before = file:{$spam}\n"
            . "\n"
            . "  imapsieve_mailbox3_name = INBOX.Junk\n"
            . "  imapsieve_mailbox3_causes = COPY APPEND\n"
            . "  imapsieve_mailbox3_before = file:{$spam}\n"
            . "\n"
            . "  imapsieve_mailbox4_name = INBOX.Spam\n"
            . "  imapsieve_mailbox4_causes = COPY APPEND\n"
            . "  imapsieve_mailbox4_before = file:{$spam}\n"
            . "\n"
            . "  # Ham gains: pulled OUT of Junk/Spam back to the Inbox -> learn as ham.\n"
            . "  # Destination is pinned to INBOX (not '*') so these can never overlap\n"
            . "  # with the spam rules above; otherwise a move between two spam folders\n"
            . "  # would fire both before-scripts in one shared Sieve context and the\n"
            . "  # second pipe is rejected as a duplicate action (whole learn fails).\n"
            . "  imapsieve_mailbox5_name = INBOX\n"
            . "  imapsieve_mailbox5_from = Junk\n"
            . "  imapsieve_mailbox5_causes = COPY\n"
            . "  imapsieve_mailbox5_before = file:{$ham}\n"
            . "\n"
            . "  imapsieve_mailbox6_name = INBOX\n"
            . "  imapsieve_mailbox6_from = Spam\n"
            . "  imapsieve_mailbox6_causes = COPY\n"
            . "  imapsieve_mailbox6_before = file:{$ham}\n"
            . "\n"
            . "  imapsieve_mailbox7_name = INBOX\n"
            . "  imapsieve_mailbox7_from = INBOX.Junk\n"
            . "  imapsieve_mailbox7_causes = COPY\n"
            . "  imapsieve_mailbox7_before = file:{$ham}\n"
            . "\n"
            . "  imapsieve_mailbox8_name = INBOX\n"
            . "  imapsieve_mailbox8_from = INBOX.Spam\n"
            . "  imapsieve_mailbox8_causes = COPY\n"
            . "  imapsieve_mailbox8_before = file:{$ham}\n"
            . "}\n";
    }

    /**
     * Install a managed global Sieve that files gateway-marked spam
     * (X-Spam-Flag: YES) into Junk, so the rules engine 'move' action lands there.
     * Idempotent, validated with doveconf, and rolled back on failure so a slip can
     * never break delivery. Best-effort: skipped if pigeonhole is absent.
     */
    private function ensureJunkSieve(string $actor): array
    {
        // Most managed mail hosts already file spam (X-Spam-Flag: YES) into a Junk
        // folder via a global sieve. If so, the 'move' action's header is filed by
        // that existing rule and we must NOT touch their Dovecot config (mailbox
        // namespaces differ, e.g. INBOX.Junk vs Junk, and conf.d may be ignored).
        if ($this->spamJunkFilingExists()) {
            return ['success' => true, 'note' => 'existing global sieve already files spam to Junk'];
        }

        if (!$this->commandExists('sievec')) {
            return ['success' => false, 'skipped' => 'no spam->Junk sieve and sievec not available'];
        }

        $dir = '/var/lib/dovecot/sieve/before.d';
        $script = $dir . '/10-devcon-junk.sieve';
        $this->ensureDir($dir);

        $sieve = "# Managed by DEVCON Mail Security. Files gateway-marked spam into Junk.\n"
            . "require [\"fileinto\", \"mailbox\"];\n"
            . "if header :contains \"X-Spam-Flag\" \"YES\" {\n"
            . "    fileinto :create \"Junk\";\n"
            . "    stop;\n"
            . "}\n";

        $scriptChanged = (@file_get_contents($script) !== $sieve) || !file_exists($script . '.svbin');
        if ($scriptChanged) {
            if (file_put_contents($script, $sieve) === false) {
                return ['success' => false, 'error' => 'failed to write Junk sieve script'];
            }
            @chmod($script, 0644);
            $this->execCommand('sievec', [$script], 30);
        }

        // Point a free sieve_before slot at our dir without clobbering an existing
        // one. If it is already configured to our dir, nothing to change.
        $slot = $this->pickSieveBeforeSlot($dir);
        if ($slot === null) {
            if ($scriptChanged) {
                $this->execCommand('systemctl', ['reload', 'dovecot'], 30);
            }
            return ['success' => true, 'note' => 'sieve_before already configured'];
        }

        $confPath = '/etc/dovecot/conf.d/99-devcon-mailsec.conf';
        $conf = "# Managed by DEVCON Mail Security. Do not edit by hand.\n"
            . "plugin {\n  {$slot} = {$dir}\n}\n";
        $originalConf = file_exists($confPath) ? (string) @file_get_contents($confPath) : null;
        if (file_put_contents($confPath, $conf) === false) {
            return ['success' => false, 'error' => 'failed to write dovecot sieve_before config'];
        }
        @chmod($confPath, 0644);

        // Validate; roll back on any error so delivery is never left broken.
        $check = $this->execCommand('doveconf', ['-n'], 30);
        if (!($check['success'] ?? false)) {
            if ($originalConf === null) {
                @unlink($confPath);
            } else {
                @file_put_contents($confPath, $originalConf);
            }
            return ['success' => false, 'error' => 'doveconf rejected sieve_before config'];
        }

        $this->execCommand('systemctl', ['reload', 'dovecot'], 30);
        return ['success' => true];
    }

    /**
     * First free sieve_before slot (sieve_before, sieve_before2, ...) or null when
     * one already points at our directory.
     */
    private function pickSieveBeforeSlot(string $dir): ?string
    {
        $want = rtrim($dir, '/');
        foreach (['sieve_before', 'sieve_before2', 'sieve_before3'] as $key) {
            $res = $this->execCommand('doveconf', ['-h', "plugin { {$key} }"], 10);
            $val = trim((string) ($res['output'] ?? ''));
            if ($val === '') {
                return $key; // free slot
            }
            if (rtrim($val, '/') === $want) {
                return null; // already ours
            }
        }
        return null; // all slots taken by others; leave config untouched
    }

    /**
     * True if the live Dovecot config already files X-Spam-Flag: YES into a Junk
     * folder via any configured global sieve (sieve_before/after/default). Per-user
     * (~) paths are ignored - we only care about server-wide filing.
     */
    private function spamJunkFilingExists(): bool
    {
        $res = $this->execCommand('doveconf', ['-n'], 15);
        $cfg = (string) ($res['output'] ?? '');
        if ($cfg === '') {
            return false;
        }

        $paths = [];
        if (preg_match_all('/^\s*sieve(?:_before\d*|_after\d*|_default)?\s*=\s*(.+)$/mi', $cfg, $m)) {
            foreach ($m[1] as $val) {
                $val = preg_replace('/;.*$/', '', preg_replace('/^\s*file:/i', '', trim($val)));
                if ($val === '' || str_contains($val, '~')) {
                    continue; // per-user script, not global
                }
                $paths[] = $val;
            }
        }

        foreach (array_unique($paths) as $p) {
            $files = [];
            if (is_dir($p)) {
                $files = (array) @glob(rtrim($p, '/') . '/*.sieve');
            } elseif (is_file($p)) {
                $files = [$p];
            }
            foreach ($files as $f) {
                $c = (string) @file_get_contents($f);
                if ($c !== ''
                    && preg_match('/x-spam-flag/i', $c)
                    && preg_match('/fileinto/i', $c)
                    && preg_match('/junk/i', $c)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function renderAntivirusConf(): string
    {
        // Fail-open: on scanner error Rspamd continues; a hit only adds a symbol.
        return "# Managed by DEVCON Mail Security.\n"
            . "clamav {\n"
            . "  type = \"clamav\";\n"
            . "  symbol = \"CLAM_VIRUS\";\n"
            . "  servers = \"/var/run/clamav/clamd.ctl\";\n"
            . "  scan_mime_parts = true;\n"
            . "  max_size = 26214400;\n"
            . "}\n";
    }

    private function readScores(): array
    {
        $file = self::RSPAMD_LOCAL . '/actions.conf';
        $scores = ['add_header' => null, 'reject' => null, 'greylist' => null];
        if (!file_exists($file)) {
            return $scores;
        }
        $content = file_get_contents($file);
        foreach (array_keys($scores) as $key) {
            if (preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*([0-9.]+)\s*;/m', $content, $m)) {
                $scores[$key] = (float)$m[1];
            }
        }
        return $scores;
    }

    private function isMilterWired(): bool
    {
        // Read the live Postfix value WITHOUT modifying anything.
        $result = $this->execCommand('postconf', ['-h', 'smtpd_milters'], 10);
        if (!$result['success']) {
            return false;
        }
        return strpos($result['output'], ':11332') !== false
            || strpos($result['output'], 'rspamd') !== false;
    }

    private function rspamdVersion(): ?string
    {
        $result = $this->execCommand('rspamadm', ['--version'], 10);
        if ($result['success'] && preg_match('/(\d+\.\d+(?:\.\d+)?)/', $result['output'], $m)) {
            return $m[1];
        }
        return null;
    }

    private function serviceActive(string $name): bool
    {
        $result = $this->execCommand('systemctl', ['is-active', $name], 10);
        return trim($result['output']) === 'active';
    }

    private function commandExists(string $cmd): bool
    {
        $result = $this->execCommand('command', ['-v', $cmd], 5);
        // 'command' is a shell builtin; fall back to known paths if unavailable.
        if ($result['success'] && trim($result['output']) !== '') {
            return true;
        }
        foreach (['/usr/bin/', '/usr/sbin/', '/bin/', '/sbin/'] as $dir) {
            if (file_exists($dir . $cmd)) {
                return true;
            }
        }
        return false;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
    }

    /**
     * Restrict config writes to Rspamd's drop-in directories. No traversal.
     */
    private function validateConfigPath(string $file): bool
    {
        if ($file === '' || str_contains($file, '..')) {
            return false;
        }
        $dir = rtrim(dirname($file), '/');
        return $dir === self::RSPAMD_LOCAL || $dir === self::RSPAMD_OVERRIDE;
    }

    private function writeConfig(string $path, string $content, string $actor): bool
    {
        $this->ensureDir(dirname($path));
        if (file_exists($path)) {
            $this->backupFile($path, 'saveConfig', $actor);
        }
        if (file_put_contents($path, $content) === false) {
            return false;
        }
        @chmod($path, 0644);
        return true;
    }
}
