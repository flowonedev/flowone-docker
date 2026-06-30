<?php

namespace VpsAdmin\Api\Controllers;

use VpsAdmin\Api\Core\Container;
use VpsAdmin\Api\Core\Migration;
use VpsAdmin\Api\Core\Request;
use VpsAdmin\Api\Core\Response;

/**
 * Mail Security Gateway (Rspamd + ClamAV) - V1 foundation.
 *
 * Engine endpoints proxy to the privileged agent (namespace `mailsec`).
 * List/settings/quarantine endpoints read/write the panel database.
 * Admin-only; every mutation is audit-logged.
 *
 * Nothing here modifies Postfix, so live mail delivery is unaffected.
 */
class MailSecurityController extends BaseController
{
    private const VALID_LIST_TYPES = ['email', 'domain', 'ip', 'cidr'];

    /** Public (no-auth) path for the self-service quarantine landing + action. */
    private const QUARANTINE_PUBLIC_PATH = '/api/mailsec-q';

    /** Ensure the schema migration runs at most once per worker process. */
    private static bool $schemaEnsured = false;

    public function __construct(Container $container)
    {
        parent::__construct($container);

        // Idempotently guarantee the mail-security tables exist before any
        // endpoint runs. This makes a "deployed code, forgot the SQL" mismatch
        // (which is how mail_security_impersonation went missing) impossible:
        // opening any Mail Security tab or hitting Re-provision self-heals it.
        if (!self::$schemaEnsured) {
            self::$schemaEnsured = true;
            try {
                (new Migration($container->getDatabase()))->migrateMailSecurity();
            } catch (\Throwable $e) {
                // Non-fatal: individual endpoints still guard their own queries.
            }
        }
    }

    // ==================== ENGINE (agent passthrough) ====================

    public function status(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        return $this->agentAction('mailsec.status');
    }

    public function install(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }

        $params = [
            'spam_score' => (float)$request->input('spam_score', 6),
            'reject_score' => (float)$request->input('reject_score', 15),
        ];

        // apt install can take minutes - allow a generous timeout.
        $result = $this->agent->execute('mailsec.install', $params, $this->getActor(), 600);

        if ($result['success']) {
            $this->logAction('mailsec.install', 'engine', 'success', ['mode' => 'monitor']);
            return Response::success($result['data'], $result['message'] ?? 'Installed');
        }

        $this->logAction('mailsec.install', 'engine', 'failed', ['error' => $result['error'] ?? 'unknown']);
        return Response::error($result['error'] ?? 'Install failed');
    }

    /**
     * Install + configure the local DNS resolver (unbound on 127.0.0.1:5335) for
     * reliable DNSBL lookups, and point Rspamd at it. Fail-safe: if unbound cannot
     * come up, Rspamd is left on the system resolver. Safe to re-run.
     */
    public function setupResolver(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        // apt install + service bring-up can take a while.
        $result = $this->agent->execute('mailsec.setupResolver', [], $this->getActor(), 360);
        if ($result['success']) {
            $configured = !empty($result['data']['configured']);
            $this->logAction('mailsec.setup_resolver', 'engine', 'success', ['configured' => $configured]);
            return Response::success($result['data'], $result['message'] ?? 'Resolver setup complete');
        }
        $this->logAction('mailsec.setup_resolver', 'engine', 'failed', ['error' => $result['error'] ?? 'unknown']);
        return Response::error($result['error'] ?? 'Resolver setup failed');
    }

    public function start(Request $request): Response
    {
        return $this->engineControl('start');
    }

    public function stop(Request $request): Response
    {
        return $this->engineControl('stop');
    }

    public function restart(Request $request): Response
    {
        return $this->engineControl('restart');
    }

    private function engineControl(string $verb): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $result = $this->agent->execute("mailsec.{$verb}", [], $this->getActor());
        $this->logAction("mailsec.{$verb}", 'engine', $result['success'] ? 'success' : 'failed');
        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'OK')
            : Response::error($result['error'] ?? 'Action failed');
    }

    public function getConfig(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $file = $request->getQuery('file', '/etc/rspamd/local.d/actions.conf');
        return $this->agentAction('mailsec.getConfig', ['file' => $file]);
    }

    public function saveConfig(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $file = $request->input('file', '');
        $content = $request->input('content_b64')
            ? base64_decode($request->input('content_b64'))
            : $request->input('content', '');

        $result = $this->agent->execute('mailsec.saveConfig', [
            'file' => $file,
            'content' => $content,
        ], $this->getActor());

        $this->logAction('mailsec.config', $file, $result['success'] ? 'success' : 'failed');
        return $result['success']
            ? Response::success($result['data'], $result['message'] ?? 'Saved')
            : Response::error($result['error'] ?? 'Save failed');
    }

    public function getScores(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        return $this->agentAction('mailsec.getScores');
    }

    public function setScores(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $spam = (float)$request->input('spam_score', 6);
        $reject = (float)$request->input('reject_score', 15);

        $result = $this->agent->execute('mailsec.setScores', [
            'spam_score' => $spam,
            'reject_score' => $reject,
        ], $this->getActor());

        if ($result['success']) {
            // Mirror to DB settings so the UI has a source of truth.
            $this->putSetting('spam_score_threshold', (string)$spam);
            $this->putSetting('reject_score_threshold', (string)$reject);
            $this->logAction('mailsec.scores', 'engine', 'success', compact('spam', 'reject'));
            return Response::success($result['data'], $result['message'] ?? 'Scores updated');
        }

        $this->logAction('mailsec.scores', 'engine', 'failed', ['error' => $result['error'] ?? 'unknown']);
        return Response::error($result['error'] ?? 'Failed to update scores');
    }

    // ==================== ANTIVIRUS (ClamAV) ====================

    /**
     * ClamAV status (engine/DB/freshclam health + live detections from Rspamd
     * history) plus virus-detection counts from our event log.
     */
    public function clamavStatus(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $result = $this->agent->execute('mailsec.clamavStatus', [], $this->getActor());
        $clamav = $result['data']['clamav'] ?? null;

        $detections = ['today' => 0, 'week' => 0, 'month' => 0, 'recent' => []];
        try {
            $db = $this->container->getDatabase();
            $detections['today'] = (int)$db->query(
                "SELECT COUNT(*) FROM mail_security_events WHERE event_type = 'virus' AND ts >= CURDATE()"
            )->fetchColumn();
            $detections['week'] = (int)$db->query(
                "SELECT COUNT(*) FROM mail_security_events WHERE event_type = 'virus' AND ts >= (CURDATE() - INTERVAL 7 DAY)"
            )->fetchColumn();
            $detections['month'] = (int)$db->query(
                "SELECT COUNT(*) FROM mail_security_events WHERE event_type = 'virus' AND ts >= (CURDATE() - INTERVAL 30 DAY)"
            )->fetchColumn();
            // The actual messages: which email, who sent it, who it was for, and
            // the malware name (stored in `symbol` by the event ingester).
            $detections['recent'] = $db->query(
                "SELECT ts, sender, recipient, domain, symbol, score
                 FROM mail_security_events
                 WHERE event_type = 'virus'
                 ORDER BY ts DESC LIMIT 100"
            )->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            // events table may be empty; zeros are fine
        }

        return Response::success(['clamav' => $clamav, 'detections' => $detections]);
    }

    public function updateClamavSignatures(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $result = $this->agent->execute('mailsec.updateSignatures', [], $this->getActor());
        if (!($result['success'] ?? false)) {
            $this->logAction('mailsec.clamav_update', 'global', 'failed', ['error' => $result['error'] ?? '']);
            return Response::error($result['error'] ?? 'Signature update failed');
        }
        $this->logAction('mailsec.clamav_update', 'global', 'success');
        return Response::success($result['data'] ?? [], $result['message'] ?? 'Signatures updated');
    }

    public function restartClamav(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $result = $this->agent->execute('mailsec.restartClamav', [], $this->getActor());
        if (!($result['success'] ?? false)) {
            $this->logAction('mailsec.clamav_restart', 'global', 'failed', ['error' => $result['error'] ?? '']);
            return Response::error($result['error'] ?? 'Restart failed');
        }
        $this->logAction('mailsec.clamav_restart', 'global', 'success');
        return Response::success($result['data'] ?? [], $result['message'] ?? 'ClamAV restarted');
    }

    public function getStats(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        return $this->agentAction('mailsec.getStats');
    }

    // ==================== DELIVERY WIRING (live mail path) ====================

    public function deliveryStatus(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        return $this->agentAction('mailsec.deliveryStatus');
    }

    /**
     * Tail a mail-related log for live monitoring (read-only). The source is an
     * allowlisted key resolved to a fixed path on the agent.
     */
    public function mailLog(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $source = $request->getQuery('source', 'mail');
        if (!in_array($source, ['mail', 'rspamd'], true)) {
            $source = 'mail';
        }
        return $this->agentAction('mailsec.tailLog', [
            'source' => $source,
            'lines' => (int)$request->getQuery('lines', 120),
            'filter' => (string)$request->getQuery('filter', ''),
        ]);
    }

    /**
     * Connect Rspamd into the live delivery path. Requires explicit confirmation
     * because it changes how inbound mail is processed (fail-open).
     */
    public function wireMilter(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        if (!filter_var($request->input('confirm', false), FILTER_VALIDATE_BOOLEAN)) {
            return Response::error('Confirmation required to change live mail delivery wiring');
        }

        $result = $this->agent->execute('mailsec.wireMilter', [], $this->getActor(), 120);
        if ($result['success']) {
            $this->putSetting('milter_wired', '1');
            $this->putSetting('mode', 'active');
            $this->syncMapsBestEffort(); // refresh rules map so enforcement mode follows
            $this->logAction('mailsec.wire_milter', 'delivery', 'success');
            return Response::success($result['data'], $result['message'] ?? 'Delivery wired');
        }

        $this->logAction('mailsec.wire_milter', 'delivery', 'failed', ['error' => $result['error'] ?? 'unknown']);
        return Response::error($result['error'] ?? 'Wiring failed');
    }

    /**
     * Disconnect Rspamd from the live delivery path. Requires confirmation.
     */
    public function unwireMilter(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        if (!filter_var($request->input('confirm', false), FILTER_VALIDATE_BOOLEAN)) {
            return Response::error('Confirmation required to change live mail delivery wiring');
        }

        $result = $this->agent->execute('mailsec.unwireMilter', [], $this->getActor(), 120);
        if ($result['success']) {
            $this->putSetting('milter_wired', '0');
            $this->putSetting('mode', 'monitor');
            $this->syncMapsBestEffort(); // refresh rules map so enforcement mode follows
            $this->logAction('mailsec.unwire_milter', 'delivery', 'success');
            return Response::success($result['data'], $result['message'] ?? 'Delivery unwired');
        }

        $this->logAction('mailsec.unwire_milter', 'delivery', 'failed', ['error' => $result['error'] ?? 'unknown']);
        return Response::error($result['error'] ?? 'Unwiring failed');
    }

    // ==================== SETTINGS (DB) ====================

    public function getSettings(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        try {
            $db = $this->container->getDatabase();
            $rows = $db->query('SELECT k, v FROM mail_security_settings')->fetchAll(\PDO::FETCH_KEY_PAIR);
            // Never expose secrets (e.g. virustotal_api_key, quarantine_link_secret)
            // through the generic settings reader - mask anything ending in _api_key
            // or _secret to a hint.
            foreach ($rows as $k => $v) {
                $ks = (string)$k;
                if ((substr($ks, -8) === '_api_key' || substr($ks, -7) === '_secret') && $v !== null && $v !== '') {
                    $rows[$k] = '***' . substr((string)$v, -4);
                }
            }
            return Response::success(['settings' => $rows]);
        } catch (\Throwable $e) {
            return Response::success(['settings' => []]);
        }
    }

    public function updateSettings(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $settings = $request->input('settings', []);
        if (!is_array($settings) || empty($settings)) {
            return Response::error('settings object is required');
        }
        foreach ($settings as $k => $v) {
            $this->putSetting((string)$k, (string)$v);
        }
        $this->logAction('mailsec.settings', 'global', 'success', ['keys' => array_keys($settings)]);

        // Lookalike config is consumed by the engine via a watched map; push it
        // so a toggle/sensitivity change applies live without re-provisioning.
        $keys = array_map('strval', array_keys($settings));
        if (array_intersect($keys, ['lookalike_enabled', 'lookalike_sensitivity'])) {
            $this->syncMapsBestEffort();
        }

        return Response::success(['updated' => array_keys($settings)], 'Settings saved');
    }

    // ==================== DASHBOARD OVERVIEW (DB) ====================

    public function overview(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        try {
            $db = $this->container->getDatabase();

            $todayCount = function (string $where) use ($db): int {
                $stmt = $db->query(
                    "SELECT COUNT(*) FROM mail_security_events WHERE ts >= CURDATE()" . ($where ? " AND {$where}" : '')
                );
                return (int)$stmt->fetchColumn();
            };

            $quarantined = (int)$db->query(
                "SELECT COUNT(*) FROM mail_quarantine WHERE status = 'quarantined'"
            )->fetchColumn();

            $topSenders = $db->query(
                "SELECT sender, COUNT(*) AS cnt FROM mail_security_events
                 WHERE event_type IN ('spam','reject') AND sender IS NOT NULL AND ts >= (CURDATE() - INTERVAL 7 DAY)
                 GROUP BY sender ORDER BY cnt DESC LIMIT 10"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $topDomains = $db->query(
                "SELECT domain, COUNT(*) AS cnt FROM mail_security_events
                 WHERE event_type IN ('spam','reject') AND domain IS NOT NULL AND ts >= (CURDATE() - INTERVAL 7 DAY)
                 GROUP BY domain ORDER BY cnt DESC LIMIT 10"
            )->fetchAll(\PDO::FETCH_ASSOC);

            // Auth-fail rows (spf/dkim/dmarc) are stored alongside the delivery
            // verdict for the same message, so "message" totals must count only
            // the verdict rows to avoid double counting.
            $verdictIn = "event_type IN ('clean','spam','reject','virus','quarantine','phish')";

            $volume = $db->query(
                "SELECT DATE(ts) AS day, SUM({$verdictIn}) AS total,
                        SUM(event_type IN ('spam','reject')) AS spam
                 FROM mail_security_events
                 WHERE ts >= (CURDATE() - INTERVAL 14 DAY)
                 GROUP BY DATE(ts) ORDER BY day ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);

            return Response::success([
                'messages_today' => $todayCount($verdictIn),
                'spam_today' => $todayCount("event_type IN ('spam','reject')"),
                'virus_today' => $todayCount("event_type = 'virus'"),
                'quarantined' => $quarantined,
                'spf_fail_today' => $todayCount("event_type = 'spf_fail'"),
                'dkim_fail_today' => $todayCount("event_type = 'dkim_fail'"),
                'dmarc_fail_today' => $todayCount("event_type = 'dmarc_fail'"),
                'top_senders' => $topSenders,
                'top_domains' => $topDomains,
                'volume' => $volume,
            ]);
        } catch (\Throwable $e) {
            // Tables may be empty / not yet ingested - return a zeroed dashboard.
            return Response::success([
                'messages_today' => 0, 'spam_today' => 0, 'virus_today' => 0,
                'quarantined' => 0, 'spf_fail_today' => 0, 'dkim_fail_today' => 0,
                'dmarc_fail_today' => 0, 'top_senders' => [], 'top_domains' => [], 'volume' => [],
            ]);
        }
    }

    // ==================== REPORTING (DB) ====================

    /**
     * Aggregated report over a period: totals by event type, daily series,
     * and top spam senders/domains. Read-only; sourced from mail_security_events.
     */
    public function report(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $days = $this->reportDays($request);
        try {
            $data = $this->buildReport($days);
            return Response::success($data);
        } catch (\Throwable $e) {
            return Response::success($this->emptyReport($days));
        }
    }

    /**
     * Same report as CSV (daily series), streamed as a download.
     */
    public function reportCsv(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $days = $this->reportDays($request);
        try {
            $data = $this->buildReport($days);
        } catch (\Throwable $e) {
            $data = $this->emptyReport($days);
        }

        $rows = [];
        $rows[] = ['Date', 'Total', 'Clean', 'Spam', 'Rejected', 'Quarantined', 'Virus'];
        foreach ($data['daily'] as $d) {
            $rows[] = [
                $d['day'],
                $d['total'],
                $d['clean'],
                $d['spam'],
                $d['reject'],
                $d['quarantine'],
                $d['virus'],
            ];
        }

        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $filename = 'mail-security-report-' . $days . 'd-' . date('Ymd') . '.csv';
        $this->logAction('mailsec.report_export', "{$days}d", 'success');

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    private function reportDays(Request $request): int
    {
        $days = (int)$request->getQuery('days', 30);
        return in_array($days, [7, 30, 90], true) ? $days : 30;
    }

    private function buildReport(int $days): array
    {
        $db = $this->container->getDatabase();
        $since = "(CURDATE() - INTERVAL " . ($days - 1) . " DAY)";

        $byType = [];
        $typeStmt = $db->query(
            "SELECT event_type, COUNT(*) AS cnt FROM mail_security_events
             WHERE ts >= {$since} GROUP BY event_type"
        );
        foreach ($typeStmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $byType[$r['event_type']] = (int)$r['cnt'];
        }

        // Total = delivery-verdict rows only; auth-fail rows (spf/dkim/dmarc) are
        // recorded per message in addition to the verdict and must not inflate it.
        $verdictTypes = ['clean', 'spam', 'reject', 'virus', 'quarantine', 'phish'];
        $total = 0;
        foreach ($verdictTypes as $t) {
            $total += $byType[$t] ?? 0;
        }
        $spam = ($byType['spam'] ?? 0) + ($byType['reject'] ?? 0);

        $daily = $db->query(
            "SELECT DATE(ts) AS day,
                    SUM(event_type IN ('clean','spam','reject','virus','quarantine','phish')) AS total,
                    SUM(event_type = 'clean') AS clean,
                    SUM(event_type = 'spam') AS spam,
                    SUM(event_type = 'reject') AS reject,
                    SUM(event_type = 'quarantine') AS quarantine,
                    SUM(event_type = 'virus') AS virus
             FROM mail_security_events
             WHERE ts >= {$since}
             GROUP BY DATE(ts) ORDER BY day ASC"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $daily = array_map(static function ($d) {
            return [
                'day' => $d['day'],
                'total' => (int)$d['total'],
                'clean' => (int)$d['clean'],
                'spam' => (int)$d['spam'],
                'reject' => (int)$d['reject'],
                'quarantine' => (int)$d['quarantine'],
                'virus' => (int)$d['virus'],
            ];
        }, $daily);

        $topSenders = $db->query(
            "SELECT sender, COUNT(*) AS cnt FROM mail_security_events
             WHERE event_type IN ('spam','reject') AND sender IS NOT NULL AND ts >= {$since}
             GROUP BY sender ORDER BY cnt DESC LIMIT 20"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $topDomains = $db->query(
            "SELECT domain, COUNT(*) AS cnt FROM mail_security_events
             WHERE event_type IN ('spam','reject') AND domain IS NOT NULL AND ts >= {$since}
             GROUP BY domain ORDER BY cnt DESC LIMIT 20"
        )->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'period_days' => $days,
            'totals' => [
                'total' => $total,
                'clean' => $byType['clean'] ?? 0,
                'spam' => $spam,
                'virus' => $byType['virus'] ?? 0,
                'quarantine' => $byType['quarantine'] ?? 0,
                'spf_fail' => $byType['spf_fail'] ?? 0,
                'dkim_fail' => $byType['dkim_fail'] ?? 0,
                'dmarc_fail' => $byType['dmarc_fail'] ?? 0,
            ],
            'by_type' => $byType,
            'daily' => $daily,
            'top_senders' => $topSenders,
            'top_domains' => $topDomains,
        ];
    }

    private function emptyReport(int $days): array
    {
        return [
            'period_days' => $days,
            'totals' => [
                'total' => 0, 'clean' => 0, 'spam' => 0, 'virus' => 0,
                'quarantine' => 0, 'spf_fail' => 0, 'dkim_fail' => 0, 'dmarc_fail' => 0,
            ],
            'by_type' => [],
            'daily' => [],
            'top_senders' => [],
            'top_domains' => [],
        ];
    }

    // ==================== GLOBAL LISTS (DB) ====================

    public function listWhitelist(Request $request): Response
    {
        return $this->listEntries('mail_security_global_whitelist');
    }

    public function listBlacklist(Request $request): Response
    {
        return $this->listEntries('mail_security_global_blacklist');
    }

    private function listEntries(string $table): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        try {
            $db = $this->container->getDatabase();
            $rows = $db->query("SELECT * FROM {$table} ORDER BY created_at DESC")->fetchAll(\PDO::FETCH_ASSOC);
            return Response::success(['entries' => $rows]);
        } catch (\Throwable $e) {
            return Response::success(['entries' => []]);
        }
    }

    public function addWhitelist(Request $request): Response
    {
        return $this->addEntry($request, 'mail_security_global_whitelist', false);
    }

    public function addBlacklist(Request $request): Response
    {
        return $this->addEntry($request, 'mail_security_global_blacklist', true);
    }

    private function addEntry(Request $request, string $table, bool $withAction): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }

        $type = strtolower(trim((string)$request->input('type', '')));
        $value = strtolower(trim((string)$request->input('value', '')));
        $description = $request->input('description');

        if (!in_array($type, self::VALID_LIST_TYPES, true)) {
            return Response::error('type must be one of: ' . implode(', ', self::VALID_LIST_TYPES));
        }
        if ($value === '') {
            return Response::error('value is required');
        }

        try {
            $db = $this->container->getDatabase();
            if ($withAction) {
                $action = $request->input('action', 'reject');
                if (!in_array($action, ['reject', 'quarantine'], true)) {
                    return Response::error("action must be 'reject' or 'quarantine'");
                }
                $stmt = $db->prepare(
                    "INSERT INTO {$table} (type, value, action, description, created_by)
                     VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE action = VALUES(action), description = VALUES(description)"
                );
                $stmt->execute([$type, $value, $action, $description, $this->getActor()]);
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO {$table} (type, value, description, created_by)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE description = VALUES(description)"
                );
                $stmt->execute([$type, $value, $description, $this->getActor()]);
            }
            $this->logAction('mailsec.list_add', "{$table}:{$value}", 'success', ['type' => $type]);
            $this->syncMapsBestEffort();
            return Response::success(['id' => (int)$db->lastInsertId()], 'Entry added');
        } catch (\Throwable $e) {
            return Response::error('Failed to add entry: ' . $e->getMessage());
        }
    }

    public function deleteWhitelist(Request $request): Response
    {
        return $this->deleteEntry($request, 'mail_security_global_whitelist');
    }

    public function deleteBlacklist(Request $request): Response
    {
        return $this->deleteEntry($request, 'mail_security_global_blacklist');
    }

    private function deleteEntry(Request $request, string $table): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $id = (int)$request->input('id', 0);
        if ($id <= 0) {
            return Response::error('id is required');
        }
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare("DELETE FROM {$table} WHERE id = ?");
            $stmt->execute([$id]);
            $this->logAction('mailsec.list_delete', "{$table}:{$id}", 'success');
            $this->syncMapsBestEffort();
            return Response::success(['deleted' => $stmt->rowCount()], 'Entry deleted');
        } catch (\Throwable $e) {
            return Response::error('Failed to delete entry: ' . $e->getMessage());
        }
    }

    public function importWhitelist(Request $request): Response
    {
        return $this->importEntries($request, 'mail_security_global_whitelist', false);
    }

    public function importBlacklist(Request $request): Response
    {
        return $this->importEntries($request, 'mail_security_global_blacklist', true);
    }

    /**
     * Bulk-import list entries from pasted CSV. Forgiving format, one entry per line:
     *   value
     *   type,value
     *   type,value,action,description     (action only honoured for blacklist)
     * Type is auto-detected when omitted. Blank lines and #comments are ignored.
     */
    private function importEntries(Request $request, string $table, bool $withAction): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $csv = (string)$request->input('csv', '');
        if (trim($csv) === '') {
            return Response::error('csv content is required');
        }

        $defaultAction = (string)$request->input('action', 'reject');
        if (!in_array($defaultAction, ['reject', 'quarantine'], true)) {
            $defaultAction = 'reject';
        }

        $imported = 0;
        $skipped = 0;
        try {
            $db = $this->container->getDatabase();
            if ($withAction) {
                $stmt = $db->prepare(
                    "INSERT INTO {$table} (type, value, action, description, created_by) VALUES (?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE action = VALUES(action), description = VALUES(description)"
                );
            } else {
                $stmt = $db->prepare(
                    "INSERT INTO {$table} (type, value, description, created_by) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE description = VALUES(description)"
                );
            }

            foreach (preg_split('/\r\n|\r|\n/', $csv) ?: [] as $rawLine) {
                $line = trim($rawLine);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $fields = array_map(static fn($f) => trim($f, " \t\"'"), explode(',', $line));

                if (in_array(strtolower($fields[0]), self::VALID_LIST_TYPES, true)) {
                    $type = strtolower($fields[0]);
                    $value = strtolower($fields[1] ?? '');
                    $rest = array_slice($fields, 2);
                } else {
                    $value = strtolower($fields[0]);
                    $type = $this->detectListType($value);
                    $rest = array_slice($fields, 1);
                }

                $action = $defaultAction;
                $description = null;
                foreach ($rest as $r) {
                    if ($withAction && in_array(strtolower($r), ['reject', 'quarantine'], true)) {
                        $action = strtolower($r);
                    } elseif ($r !== '') {
                        $description = $r;
                    }
                }

                if ($value === '' || $type === null || !$this->isValidListValue($type, $value)) {
                    $skipped++;
                    continue;
                }

                try {
                    if ($withAction) {
                        $stmt->execute([$type, $value, $action, $description, $this->getActor()]);
                    } else {
                        $stmt->execute([$type, $value, $description, $this->getActor()]);
                    }
                    $imported++;
                } catch (\Throwable $e) {
                    $skipped++;
                }
            }

            $this->logAction('mailsec.list_import', $table, 'success', ['imported' => $imported, 'skipped' => $skipped]);
            if ($imported > 0) {
                $this->syncMapsBestEffort();
            }
            return Response::success(
                ['imported' => $imported, 'skipped' => $skipped],
                "Imported {$imported}, skipped {$skipped}"
            );
        } catch (\Throwable $e) {
            return Response::error('Import failed: ' . $e->getMessage());
        }
    }

    public function exportWhitelist(Request $request): Response
    {
        return $this->exportEntries('mail_security_global_whitelist', false, 'whitelist');
    }

    public function exportBlacklist(Request $request): Response
    {
        return $this->exportEntries('mail_security_global_blacklist', true, 'blacklist');
    }

    private function exportEntries(string $table, bool $withAction, string $label): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        try {
            $db = $this->container->getDatabase();
            $rows = $db->query("SELECT * FROM {$table} ORDER BY type, value")->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $rows = [];
        }

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $withAction ? ['type', 'value', 'action', 'description'] : ['type', 'value', 'description']);
        foreach ($rows as $r) {
            $line = $withAction
                ? [$r['type'] ?? '', $r['value'] ?? '', $r['action'] ?? 'reject', $r['description'] ?? '']
                : [$r['type'] ?? '', $r['value'] ?? '', $r['description'] ?? ''];
            fputcsv($fh, $line);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $filename = 'mailsec-' . $label . '-' . date('Ymd') . '.csv';
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function detectListType(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (str_contains($value, '@')) {
            return 'email';
        }
        if (str_contains($value, '/')) {
            return 'cidr';
        }
        if (filter_var($value, FILTER_VALIDATE_IP)) {
            return 'ip';
        }
        if (str_contains($value, '.')) {
            return 'domain';
        }
        return null;
    }

    private function isValidListValue(string $type, string $value): bool
    {
        switch ($type) {
            case 'email':
                return (bool)filter_var($value, FILTER_VALIDATE_EMAIL);
            case 'ip':
                return (bool)filter_var($value, FILTER_VALIDATE_IP);
            case 'cidr':
                if (!str_contains($value, '/')) {
                    return false;
                }
                [$ip, $mask] = explode('/', $value, 2);
                return (bool)filter_var($ip, FILTER_VALIDATE_IP) && is_numeric($mask)
                    && (int)$mask >= 0 && (int)$mask <= 128;
            case 'domain':
                return (bool)preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $value);
        }
        return false;
    }

    // ==================== ANTI-SPOOFING / CEO FRAUD (DB) ====================

    private const IMP_KINDS = ['vip_name', 'protected_domain', 'exempt_sender'];

    /**
     * All anti-spoofing list entries, plus the auto-derived hosted domains (shown
     * read-only so the admin can see what's already covered).
     */
    public function listImpersonation(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        try {
            $db = $this->container->getDatabase();
            $rows = $db->query(
                "SELECT id, kind, value, note, created_at FROM mail_security_impersonation ORDER BY kind, value"
            )->fetchAll(\PDO::FETCH_ASSOC);

            $hosted = [];
            try {
                $has = $db->query(
                    "SELECT COUNT(*) FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = 'mail_domains'"
                )->fetchColumn();
                if ($has) {
                    $hosted = $db->query('SELECT domain FROM mail_domains ORDER BY domain')->fetchAll(\PDO::FETCH_COLUMN);
                }
            } catch (\Throwable $e) {
                // no hosted-domains table
            }

            return Response::success(['entries' => $rows, 'hosted_domains' => $hosted]);
        } catch (\Throwable $e) {
            return Response::success(['entries' => [], 'hosted_domains' => []]);
        }
    }

    public function addImpersonation(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }

        $kind = strtolower(trim((string)$request->input('kind', '')));
        $value = trim((string)$request->input('value', ''));
        $note = $request->input('note');

        if (!in_array($kind, self::IMP_KINDS, true)) {
            return Response::error('kind must be one of: ' . implode(', ', self::IMP_KINDS));
        }
        if ($value === '') {
            return Response::error('value is required');
        }

        // Validate per kind. VIP names are free text; domains/senders are strict.
        if ($kind === 'protected_domain') {
            $value = strtolower($value);
            if (!$this->isValidListValue('domain', $value)) {
                return Response::error('value must be a valid domain');
            }
        } elseif ($kind === 'exempt_sender') {
            $value = strtolower($value);
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return Response::error('value must be a valid email address');
            }
        } else {
            // vip_name: keep as entered (display), but cap length
            $value = mb_substr($value, 0, 255);
        }

        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare(
                "INSERT INTO mail_security_impersonation (kind, value, note, created_by)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE note = VALUES(note)"
            );
            $stmt->execute([$kind, $value, $note, $this->getActor()]);
            $this->logAction('mailsec.impersonation_add', "{$kind}:{$value}", 'success');
            $this->syncMapsBestEffort();
            return Response::success(['id' => (int)$db->lastInsertId()], 'Entry added');
        } catch (\Throwable $e) {
            return Response::error('Failed to add entry: ' . $e->getMessage());
        }
    }

    public function deleteImpersonation(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $id = (int)$request->input('id', 0);
        if ($id <= 0) {
            return Response::error('id is required');
        }
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare("DELETE FROM mail_security_impersonation WHERE id = ?");
            $stmt->execute([$id]);
            $this->logAction('mailsec.impersonation_delete', (string)$id, 'success');
            $this->syncMapsBestEffort();
            return Response::success([], 'Entry removed');
        } catch (\Throwable $e) {
            return Response::error('Failed to remove entry: ' . $e->getMessage());
        }
    }

    // ==================== MAIL FLOW RULES ENGINE (DB) ====================

    private const RULE_ACTIONS = ['move', 'delete', 'quarantine', 'reject', 'tag'];
    /** field => allowed operators. Conditions within a rule are AND-ed. */
    private const RULE_FIELD_OPS = [
        'from'       => ['equals', 'contains', 'domain_is', 'regex'],
        'to'         => ['equals', 'contains', 'domain_is', 'regex'],
        'subject'    => ['equals', 'contains', 'regex'],
        'header'     => ['exists', 'contains', 'regex'],
        'score'      => ['gte'],
        'symbol'     => ['has'],
        'attachment' => ['ext', 'regex'],
        'size'       => ['gte'],
    ];

    public function listRules(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        try {
            $db = $this->container->getDatabase();
            $rows = $db->query(
                "SELECT id, name, enabled, priority, conditions_json, action, action_arg, created_at
                 FROM mail_security_rules ORDER BY priority ASC, id ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['enabled'] = (int)$r['enabled'];
                $r['priority'] = (int)$r['priority'];
                $decoded = $r['conditions_json'] !== null ? json_decode((string)$r['conditions_json'], true) : [];
                $r['conditions'] = is_array($decoded) ? $decoded : [];
                unset($r['conditions_json']);
            }
            unset($r);
            $mode = (string)($db->query("SELECT v FROM mail_security_settings WHERE k = 'mode'")->fetchColumn() ?: 'monitor');
            return Response::success(['rules' => $rows, 'mode' => $mode]);
        } catch (\Throwable $e) {
            return Response::success(['rules' => [], 'mode' => 'monitor']);
        }
    }

    public function createRule(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $parsed = $this->parseRuleInput($request);
        if (isset($parsed['error'])) {
            return Response::error($parsed['error']);
        }
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare(
                "INSERT INTO mail_security_rules (name, enabled, priority, conditions_json, action, action_arg, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $parsed['name'], $parsed['enabled'], $parsed['priority'],
                json_encode($parsed['conditions']), $parsed['action'], $parsed['action_arg'], $this->getActor(),
            ]);
            $id = (int)$db->lastInsertId();
            $this->logAction('mailsec.rule_create', "{$id}:{$parsed['name']}", 'success');
            $this->syncMapsBestEffort();
            return Response::success(['id' => $id], 'Rule created');
        } catch (\Throwable $e) {
            return Response::error('Failed to create rule: ' . $e->getMessage());
        }
    }

    public function updateRule(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $id = (int)$request->input('id', 0);
        if ($id <= 0) {
            return Response::error('id is required');
        }
        $parsed = $this->parseRuleInput($request);
        if (isset($parsed['error'])) {
            return Response::error($parsed['error']);
        }
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare(
                "UPDATE mail_security_rules
                 SET name = ?, enabled = ?, priority = ?, conditions_json = ?, action = ?, action_arg = ?
                 WHERE id = ?"
            );
            $stmt->execute([
                $parsed['name'], $parsed['enabled'], $parsed['priority'],
                json_encode($parsed['conditions']), $parsed['action'], $parsed['action_arg'], $id,
            ]);
            $this->logAction('mailsec.rule_update', (string)$id, 'success');
            $this->syncMapsBestEffort();
            return Response::success(['id' => $id], 'Rule saved');
        } catch (\Throwable $e) {
            return Response::error('Failed to save rule: ' . $e->getMessage());
        }
    }

    public function deleteRule(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $id = (int)$request->input('id', 0);
        if ($id <= 0) {
            return Response::error('id is required');
        }
        try {
            $db = $this->container->getDatabase();
            $db->prepare("DELETE FROM mail_security_rules WHERE id = ?")->execute([$id]);
            $this->logAction('mailsec.rule_delete', (string)$id, 'success');
            $this->syncMapsBestEffort();
            return Response::success([], 'Rule removed');
        } catch (\Throwable $e) {
            return Response::error('Failed to remove rule: ' . $e->getMessage());
        }
    }

    /**
     * Validate + normalize a rule from the request. Returns the clean fields or
     * ['error' => '...']. Conditions are AND-ed; an empty set is a catch-all.
     */
    private function parseRuleInput(Request $request): array
    {
        $name = trim((string)$request->input('name', ''));
        if ($name === '') {
            return ['error' => 'name is required'];
        }
        $action = strtolower(trim((string)$request->input('action', '')));
        if (!in_array($action, self::RULE_ACTIONS, true)) {
            return ['error' => 'action must be one of: ' . implode(', ', self::RULE_ACTIONS)];
        }
        $priority = (int)$request->input('priority', 100);
        if ($priority < 1) {
            $priority = 1;
        }
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        $actionArg = $request->input('action_arg');
        $actionArg = ($actionArg === null || $actionArg === '') ? null : mb_substr((string)$actionArg, 0, 255);

        $rawConditions = $request->input('conditions', []);
        if (!is_array($rawConditions)) {
            return ['error' => 'conditions must be an array'];
        }
        $conditions = [];
        foreach ($rawConditions as $c) {
            if (!is_array($c)) {
                continue;
            }
            $field = strtolower(trim((string)($c['field'] ?? '')));
            $op = strtolower(trim((string)($c['op'] ?? '')));
            $value = trim((string)($c['value'] ?? ''));
            $hname = strtolower(trim((string)($c['name'] ?? '')));

            if (!isset(self::RULE_FIELD_OPS[$field])) {
                return ['error' => "unknown condition field: {$field}"];
            }
            if (!in_array($op, self::RULE_FIELD_OPS[$field], true)) {
                return ['error' => "operator '{$op}' is not valid for field '{$field}'"];
            }
            if ($field === 'header') {
                if ($hname === '') {
                    return ['error' => 'header conditions require a header name'];
                }
                if ($op !== 'exists' && $value === '') {
                    return ['error' => 'header condition value is required'];
                }
            } elseif ($value === '') {
                return ['error' => "condition value is required for field '{$field}'"];
            }
            if (in_array($field, ['score', 'size'], true) && !is_numeric($value)) {
                return ['error' => "'{$field}' requires a numeric value"];
            }
            if ($op === 'regex' && @preg_match('/' . str_replace('/', '\\/', $value) . '/', '') === false) {
                return ['error' => 'invalid regular expression in a condition'];
            }
            $cond = ['field' => $field, 'op' => $op, 'value' => $value];
            if ($field === 'header') {
                $cond['name'] = $hname;
            }
            $conditions[] = $cond;
        }

        return [
            'name' => mb_substr($name, 0, 255),
            'enabled' => $enabled,
            'priority' => $priority,
            'action' => $action,
            'action_arg' => $actionArg,
            'conditions' => $conditions,
        ];
    }

    /**
     * Compile enabled rules (+ current enforcement mode) for the engine. Sorted
     * by priority so the Lua interpreter can apply first-match-wins.
     */
    private function buildRules(): array
    {
        $out = ['mode' => 'monitor', 'rules' => []];
        try {
            $db = $this->container->getDatabase();
            $mode = (string)($db->query("SELECT v FROM mail_security_settings WHERE k = 'mode'")->fetchColumn() ?: 'monitor');
            $out['mode'] = (strtolower($mode) === 'active') ? 'active' : 'monitor';

            $rows = $db->query(
                "SELECT id, name, priority, conditions_json, action, action_arg
                 FROM mail_security_rules WHERE enabled = 1 ORDER BY priority ASC, id ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $conds = [];
                if (!empty($r['conditions_json'])) {
                    $decoded = json_decode((string)$r['conditions_json'], true);
                    if (is_array($decoded)) {
                        $conds = $decoded;
                    }
                }
                $out['rules'][] = [
                    'id' => (int)$r['id'],
                    'name' => (string)$r['name'],
                    'priority' => (int)$r['priority'],
                    'action' => (string)$r['action'],
                    'arg' => $r['action_arg'] !== null ? (string)$r['action_arg'] : '',
                    'conditions' => $conds,
                ];
            }
        } catch (\Throwable $e) {
            // Best-effort: an empty ruleset simply disables the engine rules.
        }
        return $out;
    }

    // ==================== GEO-IP COUNTRY FILTERING (DB) ====================
    // Country is resolved at scan time by Rspamd's ASN module (DNS-based, no
    // local MaxMind DB). A global policy (mail_security_settings.geoip_*) applies
    // to every recipient domain unless that domain has an override row in
    // mail_security_geoip. Enforcement still respects the gateway mode: in
    // 'monitor' the engine only tags; in 'active' it applies the chosen action.

    private const GEOIP_ACTIONS = ['reject', 'quarantine', 'tag'];
    private const GEOIP_MODES = ['allow', 'deny'];

    public function getGeoip(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        try {
            $db = $this->container->getDatabase();
            $settings = $db->query(
                "SELECT k, v FROM mail_security_settings
                 WHERE k IN ('geoip_enabled', 'geoip_mode', 'geoip_countries', 'geoip_action', 'mode')"
            )->fetchAll(\PDO::FETCH_KEY_PAIR);

            $domains = $db->query(
                "SELECT id, domain, mode, countries, action, created_at
                 FROM mail_security_geoip ORDER BY domain ASC"
            )->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($domains as &$d) {
                $d['id'] = (int)$d['id'];
            }
            unset($d);

            return Response::success([
                'enabled'   => in_array(strtolower((string)($settings['geoip_enabled'] ?? '0')), ['1', 'true', 'yes', 'on'], true),
                'mode'      => in_array(($settings['geoip_mode'] ?? 'deny'), self::GEOIP_MODES, true) ? $settings['geoip_mode'] : 'deny',
                'countries' => (string)($settings['geoip_countries'] ?? ''),
                'action'    => in_array(($settings['geoip_action'] ?? 'reject'), self::GEOIP_ACTIONS, true) ? $settings['geoip_action'] : 'reject',
                'domains'   => $domains,
                'gateway_mode' => (string)($settings['mode'] ?? 'monitor'),
                'hosted_domains' => $this->hostedDomains(),
            ]);
        } catch (\Throwable $e) {
            return Response::success([
                'enabled' => false, 'mode' => 'deny', 'countries' => '', 'action' => 'reject',
                'domains' => [], 'gateway_mode' => 'monitor', 'hosted_domains' => [],
            ]);
        }
    }

    public function updateGeoip(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $enabled = filter_var($request->input('enabled', false), FILTER_VALIDATE_BOOLEAN) ? '1' : '0';
        $mode = strtolower(trim((string)$request->input('mode', 'deny')));
        if (!in_array($mode, self::GEOIP_MODES, true)) {
            return Response::error('mode must be allow or deny');
        }
        $action = strtolower(trim((string)$request->input('action', 'reject')));
        if (!in_array($action, self::GEOIP_ACTIONS, true)) {
            return Response::error('action must be one of: ' . implode(', ', self::GEOIP_ACTIONS));
        }
        $countries = $this->normalizeCountryList((string)$request->input('countries', ''));
        if ($enabled === '1' && $countries === '') {
            return Response::error('Add at least one ISO country code (e.g. CN, RU) before enabling Geo-IP filtering');
        }
        try {
            $this->putSetting('geoip_enabled', $enabled);
            $this->putSetting('geoip_mode', $mode);
            $this->putSetting('geoip_countries', $countries);
            $this->putSetting('geoip_action', $action);
            $this->logAction('mailsec.geoip_settings', 'global', 'success', ['enabled' => $enabled, 'mode' => $mode]);
            $this->syncMapsBestEffort();
            return Response::success(['countries' => $countries], 'Geo-IP policy saved');
        } catch (\Throwable $e) {
            return Response::error('Failed to save Geo-IP policy: ' . $e->getMessage());
        }
    }

    public function addGeoipDomain(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $domain = strtolower(trim((string)$request->input('domain', '')));
        $domain = ltrim($domain, '@');
        if ($domain === '' || !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
            return Response::error('A valid recipient domain is required');
        }
        $mode = strtolower(trim((string)$request->input('mode', 'deny')));
        if (!in_array($mode, self::GEOIP_MODES, true)) {
            return Response::error('mode must be allow or deny');
        }
        $action = strtolower(trim((string)$request->input('action', 'reject')));
        if (!in_array($action, self::GEOIP_ACTIONS, true)) {
            return Response::error('action must be one of: ' . implode(', ', self::GEOIP_ACTIONS));
        }
        $countries = $this->normalizeCountryList((string)$request->input('countries', ''));
        if ($countries === '') {
            return Response::error('Add at least one ISO country code (e.g. CN, RU)');
        }
        try {
            $db = $this->container->getDatabase();
            // Upsert so editing a domain's policy reuses the same row.
            $stmt = $db->prepare(
                "INSERT INTO mail_security_geoip (domain, mode, countries, action, created_by)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE mode = VALUES(mode), countries = VALUES(countries), action = VALUES(action)"
            );
            $stmt->execute([$domain, $mode, $countries, $action, $this->getActor()]);
            $this->logAction('mailsec.geoip_domain', $domain, 'success', ['mode' => $mode]);
            $this->syncMapsBestEffort();
            return Response::success([], 'Domain override saved');
        } catch (\Throwable $e) {
            return Response::error('Failed to save domain override: ' . $e->getMessage());
        }
    }

    public function deleteGeoipDomain(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $id = (int)$request->input('id', 0);
        if ($id <= 0) {
            return Response::error('id is required');
        }
        try {
            $db = $this->container->getDatabase();
            $db->prepare("DELETE FROM mail_security_geoip WHERE id = ?")->execute([$id]);
            $this->logAction('mailsec.geoip_domain_delete', (string)$id, 'success');
            $this->syncMapsBestEffort();
            return Response::success([], 'Domain override removed');
        } catch (\Throwable $e) {
            return Response::error('Failed to remove domain override: ' . $e->getMessage());
        }
    }

    /**
     * Normalize a free-form country input into a clean, deduped, uppercase CSV
     * of ISO 3166-1 alpha-2 codes. Accepts comma/space/newline separators.
     */
    private function normalizeCountryList(string $raw): string
    {
        $parts = preg_split('/[\s,;]+/', strtoupper($raw)) ?: [];
        $seen = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if (preg_match('/^[A-Z]{2}$/', $p)) {
                $seen[$p] = true;
            }
        }
        return implode(',', array_keys($seen));
    }

    /**
     * Hosted mail domains (best-effort) so the UI can offer a per-domain picker.
     */
    private function hostedDomains(): array
    {
        try {
            $db = $this->container->getDatabase();
            $has = $db->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'mail_domains'"
            )->fetchColumn();
            if (!$has) {
                return [];
            }
            return array_values($db->query('SELECT domain FROM mail_domains ORDER BY domain ASC')->fetchAll(\PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Compile the Geo-IP policy (+ current enforcement mode) for the engine.
     * Country codes are arrays; the agent serializes them into the watched map.
     */
    private function buildGeoip(): array
    {
        $out = [
            'mode'    => 'monitor',
            'enabled' => false,
            'default' => ['mode' => 'deny', 'countries' => [], 'action' => 'reject'],
            'domains' => [],
        ];
        try {
            $db = $this->container->getDatabase();
            $s = $db->query(
                "SELECT k, v FROM mail_security_settings
                 WHERE k IN ('geoip_enabled', 'geoip_mode', 'geoip_countries', 'geoip_action', 'mode')"
            )->fetchAll(\PDO::FETCH_KEY_PAIR);

            $out['mode'] = (strtolower((string)($s['mode'] ?? 'monitor')) === 'active') ? 'active' : 'monitor';
            $out['enabled'] = in_array(strtolower((string)($s['geoip_enabled'] ?? '0')), ['1', 'true', 'yes', 'on'], true);
            $out['default'] = [
                'mode'      => in_array(($s['geoip_mode'] ?? 'deny'), self::GEOIP_MODES, true) ? $s['geoip_mode'] : 'deny',
                'countries' => $this->countryArray((string)($s['geoip_countries'] ?? '')),
                'action'    => in_array(($s['geoip_action'] ?? 'reject'), self::GEOIP_ACTIONS, true) ? $s['geoip_action'] : 'reject',
            ];

            $rows = $db->query("SELECT domain, mode, countries, action FROM mail_security_geoip")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $dom = strtolower((string)$r['domain']);
                if ($dom === '') {
                    continue;
                }
                $out['domains'][$dom] = [
                    'mode'      => in_array($r['mode'], self::GEOIP_MODES, true) ? $r['mode'] : 'deny',
                    'countries' => $this->countryArray((string)$r['countries']),
                    'action'    => in_array($r['action'], self::GEOIP_ACTIONS, true) ? $r['action'] : 'reject',
                ];
            }
        } catch (\Throwable $e) {
            // Best-effort: an empty/disabled policy is a no-op in the engine.
        }
        return $out;
    }

    private function countryArray(string $csv): array
    {
        $out = [];
        foreach (explode(',', strtoupper($csv)) as $c) {
            $c = trim($c);
            if ($c !== '') {
                $out[] = $c;
            }
        }
        return array_values(array_unique($out));
    }

    // ==================== ENGINE SYNC (lists -> Rspamd maps) ====================

    /**
     * Push the current global lists into Rspamd multimaps via the agent.
     * Safe: only writes Rspamd map/config files; Postfix is untouched.
     */
    public function syncEngine(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }

        $result = $this->pushEngineConfig();

        if ($result['success']) {
            $this->logAction('mailsec.sync', 'engine', 'success', ['counts' => $result['data']['written'] ?? []]);
            return Response::success($result['data'], $result['message'] ?? 'Synced to engine');
        }

        $this->logAction('mailsec.sync', 'engine', 'failed', ['error' => $result['error'] ?? 'unknown']);
        return Response::error($result['error'] ?? 'Sync failed');
    }

    /**
     * Render the full engine config from the DB (global lists + banned
     * attachment extensions) and push it via the agent. Returns the raw agent
     * result. Safe: agent writes only Rspamd files and reloads after configtest.
     */
    private function pushEngineConfig(): array
    {
        return $this->agent->execute('mailsec.exportMaps', [
            'maps' => $this->buildMaps(),
            'bad_extensions' => $this->buildBadExtensions(),
            'impersonation' => $this->buildImpersonation(),
            'rules' => $this->buildRules(),
            'geoip' => $this->buildGeoip(),
        ], $this->getActor());
    }

    /**
     * Assemble the anti-spoofing lists for the engine. Protected domains are the
     * hosted mail_domains UNION any admin-added protected_domain entries, so the
     * common case needs zero manual domain entry.
     */
    private function buildImpersonation(): array
    {
        $out = [
            'vip_names' => [],
            'protected_domains' => [],
            'exempt_senders' => [],
            'lookalike' => $this->lookalikeSettings(),
        ];
        try {
            $db = $this->container->getDatabase();

            $rows = $db->query("SELECT kind, value FROM mail_security_impersonation")->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                switch ($r['kind']) {
                    case 'vip_name':
                        $out['vip_names'][] = $r['value'];
                        break;
                    case 'protected_domain':
                        $out['protected_domains'][] = $r['value'];
                        break;
                    case 'exempt_sender':
                        $out['exempt_senders'][] = $r['value'];
                        break;
                }
            }

            // Auto-include hosted domains as "ours" (best-effort: table may not exist).
            try {
                $has = $db->query(
                    "SELECT COUNT(*) FROM information_schema.tables
                     WHERE table_schema = DATABASE() AND table_name = 'mail_domains'"
                )->fetchColumn();
                if ($has) {
                    $hosted = $db->query('SELECT domain FROM mail_domains')->fetchAll(\PDO::FETCH_COLUMN);
                    foreach ($hosted as $d) {
                        $out['protected_domains'][] = $d;
                    }
                }
            } catch (\Throwable $e) {
                // no hosted-domains table; manual protected_domain entries still apply
            }

            $out['protected_domains'] = array_values(array_unique($out['protected_domains']));
        } catch (\Throwable $e) {
            // Best-effort: empty lists simply clear the engine maps.
        }
        return $out;
    }

    /**
     * Lookalike-detection runtime config from settings. Absent keys default to
     * enabled + medium sensitivity so existing installs need no migration.
     */
    private function lookalikeSettings(): array
    {
        $enabled = true;
        $sensitivity = 'medium';
        try {
            $db = $this->container->getDatabase();
            $rows = $db->query(
                "SELECT k, v FROM mail_security_settings
                 WHERE k IN ('lookalike_enabled', 'lookalike_sensitivity')"
            )->fetchAll(\PDO::FETCH_KEY_PAIR);
            if (array_key_exists('lookalike_enabled', $rows)) {
                $enabled = in_array(strtolower((string)$rows['lookalike_enabled']), ['1', 'true', 'yes', 'on'], true);
            }
            if (!empty($rows['lookalike_sensitivity'])) {
                $s = strtolower((string)$rows['lookalike_sensitivity']);
                if (in_array($s, ['low', 'medium', 'high'], true)) {
                    $sensitivity = $s;
                }
            }
        } catch (\Throwable $e) {
            // defaults below
        }
        return ['enabled' => $enabled, 'sensitivity' => $sensitivity];
    }

    /**
     * Blocked attachment extensions from the policy table.
     */
    private function buildBadExtensions(): array
    {
        // Grouped by the admin's per-extension action so the engine can pin each
        // outcome. 'warn' is treated as a soft hold (quarantine) for review.
        $grouped = ['reject' => [], 'quarantine' => []];
        try {
            $db = $this->container->getDatabase();
            $rows = $db->query("SELECT extension, action FROM mail_security_attachment_policy WHERE list_type = 'block'")
                ->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $ext = (string)($row['extension'] ?? '');
                if ($ext === '') {
                    continue;
                }
                $bucket = (($row['action'] ?? 'quarantine') === 'reject') ? 'reject' : 'quarantine';
                $grouped[$bucket][] = $ext;
            }
        } catch (\Throwable $e) {
            // Best-effort: empty groups simply clear the engine maps.
        }
        return $grouped;
    }

    /**
     * Group the DB lists into the map buckets the agent expects.
     */
    private function buildMaps(): array
    {
        $maps = array_fill_keys([
            'mailsec_whitelist_email', 'mailsec_whitelist_domain', 'mailsec_whitelist_ip',
            'mailsec_blacklist_email', 'mailsec_blacklist_domain', 'mailsec_blacklist_ip',
        ], []);

        try {
            $db = $this->container->getDatabase();
            $sources = [
                'whitelist' => 'mail_security_global_whitelist',
                'blacklist' => 'mail_security_global_blacklist',
            ];
            foreach ($sources as $kind => $table) {
                $rows = $db->query("SELECT type, value FROM {$table}")->fetchAll(\PDO::FETCH_ASSOC);
                foreach ($rows as $r) {
                    $bucket = ($r['type'] === 'ip' || $r['type'] === 'cidr') ? 'ip' : $r['type'];
                    $key = "mailsec_{$kind}_{$bucket}";
                    if (isset($maps[$key])) {
                        $maps[$key][] = $r['value'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Return whatever buckets we have; agent will write empty maps.
        }

        return $maps;
    }

    /**
     * Best-effort resync after a list change. Never blocks the API response on
     * agent availability.
     */
    private function syncMapsBestEffort(): void
    {
        try {
            $this->pushEngineConfig();
        } catch (\Throwable $e) {
            // Non-fatal: a manual "Sync to engine" can recover.
        }
    }

    // ==================== PER-USER LISTS (MailFlow webmail_* tables) ====================
    // The Panel API and MailFlow share one database, so per-user safe/blocked
    // senders are read/written directly here. After any edit we ask the agent to
    // regenerate that user's MailFlow Sieve script so the change takes effect at
    // delivery (MailFlow's existing enforcement path stays the source of truth).

    /**
     * Overview of users that have any per-user allow/block entries, with counts.
     * Optional ?search= filters by email substring.
     */
    public function listUserListUsers(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        try {
            $db = $this->container->getDatabase();
            if (!$this->webmailListsAvailable($db)) {
                return Response::success(['users' => [], 'available' => false], 'Email app not detected');
            }

            $search = strtolower(trim((string)$request->input('search', '')));
            $where = $search !== '' ? 'WHERE u.user_email LIKE ?' : '';
            $sql = "
                SELECT u.user_email,
                       COALESCE(b.cnt, 0) AS blocked_count,
                       COALESCE(s.cnt, 0) AS safe_count
                FROM (
                    SELECT user_email FROM webmail_blocked_senders
                    UNION
                    SELECT user_email FROM webmail_safe_senders
                ) u
                LEFT JOIN (SELECT user_email, COUNT(*) cnt FROM webmail_blocked_senders GROUP BY user_email) b
                       ON b.user_email = u.user_email
                LEFT JOIN (SELECT user_email, COUNT(*) cnt FROM webmail_safe_senders GROUP BY user_email) s
                       ON s.user_email = u.user_email
                {$where}
                ORDER BY u.user_email
                LIMIT 500";
            $stmt = $db->prepare($sql);
            $stmt->execute($search !== '' ? ['%' . $search . '%'] : []);
            return Response::success(['users' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'available' => true]);
        } catch (\Throwable $e) {
            return Response::error('Failed to load users: ' . $e->getMessage());
        }
    }

    /**
     * One user's blocked + safe senders. Requires ?user=<email>.
     */
    public function getUserLists(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $user = strtolower(trim((string)$request->input('user', '')));
        if ($user === '' || !filter_var($user, FILTER_VALIDATE_EMAIL)) {
            return Response::error('A valid user email is required');
        }
        try {
            $db = $this->container->getDatabase();
            if (!$this->webmailListsAvailable($db)) {
                return Response::success(['user' => $user, 'blocked' => [], 'safe' => [], 'available' => false]);
            }
            $b = $db->prepare('SELECT id, blocked_email, blocked_domain, reason, created_at FROM webmail_blocked_senders WHERE user_email = ? ORDER BY created_at DESC');
            $b->execute([$user]);
            $s = $db->prepare('SELECT id, safe_email, safe_domain, created_at FROM webmail_safe_senders WHERE user_email = ? ORDER BY created_at DESC');
            $s->execute([$user]);
            return Response::success([
                'user' => $user,
                'blocked' => $b->fetchAll(\PDO::FETCH_ASSOC),
                'safe' => $s->fetchAll(\PDO::FETCH_ASSOC),
                'available' => true,
            ]);
        } catch (\Throwable $e) {
            return Response::error('Failed to load lists: ' . $e->getMessage());
        }
    }

    public function addUserBlocked(Request $request): Response
    {
        return $this->addUserEntry($request, 'blocked');
    }

    public function addUserSafe(Request $request): Response
    {
        return $this->addUserEntry($request, 'safe');
    }

    private function addUserEntry(Request $request, string $kind): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $user = strtolower(trim((string)$request->input('user', '')));
        $value = strtolower(trim((string)$request->input('value', '')));
        $applyDomain = filter_var($request->input('apply_domain', false), FILTER_VALIDATE_BOOLEAN);

        if ($user === '' || !filter_var($user, FILTER_VALIDATE_EMAIL)) {
            return Response::error('A valid user email is required');
        }
        if ($value === '' || !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return Response::error('A valid sender email is required');
        }
        $domain = $applyDomain ? $this->emailDomain($value) : null;

        try {
            $db = $this->container->getDatabase();
            if ($kind === 'blocked') {
                $reason = $request->input('reason');
                $stmt = $db->prepare(
                    'INSERT INTO webmail_blocked_senders (user_email, blocked_email, blocked_domain, reason)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE blocked_domain = VALUES(blocked_domain), reason = VALUES(reason)'
                );
                $stmt->execute([$user, $value, $domain, $reason]);
            } else {
                $stmt = $db->prepare(
                    'INSERT INTO webmail_safe_senders (user_email, safe_email, safe_domain)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE safe_domain = VALUES(safe_domain)'
                );
                $stmt->execute([$user, $value, $domain]);
            }
            $this->logAction('mailsec.userlist_add', "{$kind}:{$user}:{$value}", 'success');
            $resync = $this->triggerSieveResync($user);
            return Response::success(['id' => (int)$db->lastInsertId(), 'resync' => $resync], 'Entry added');
        } catch (\Throwable $e) {
            return Response::error('Failed to add entry: ' . $e->getMessage());
        }
    }

    public function deleteUserBlocked(Request $request): Response
    {
        return $this->deleteUserEntry($request, 'blocked');
    }

    public function deleteUserSafe(Request $request): Response
    {
        return $this->deleteUserEntry($request, 'safe');
    }

    private function deleteUserEntry(Request $request, string $kind): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $user = strtolower(trim((string)$request->input('user', '')));
        $id = (int)$request->input('id', 0);
        if ($user === '' || !filter_var($user, FILTER_VALIDATE_EMAIL)) {
            return Response::error('A valid user email is required');
        }
        if ($id <= 0) {
            return Response::error('id is required');
        }
        $table = $kind === 'blocked' ? 'webmail_blocked_senders' : 'webmail_safe_senders';
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare("DELETE FROM {$table} WHERE id = ? AND user_email = ?");
            $stmt->execute([$id, $user]);
            $deleted = $stmt->rowCount();
            $this->logAction('mailsec.userlist_delete', "{$kind}:{$user}:{$id}", 'success');
            $resync = $deleted > 0 ? $this->triggerSieveResync($user) : ['synced' => false];
            return Response::success(['deleted' => $deleted, 'resync' => $resync], 'Entry removed');
        } catch (\Throwable $e) {
            return Response::error('Failed to remove entry: ' . $e->getMessage());
        }
    }

    /**
     * Regenerate the user's MailFlow Sieve so an admin edit takes effect at delivery.
     * Returns ['synced' => bool, 'warning' => ?string]; never throws.
     */
    private function triggerSieveResync(string $email): array
    {
        try {
            $result = $this->agent->execute('mailsec.syncUserSieve', ['email' => $email], $this->getActor());
            return [
                'synced' => (bool)($result['success'] ?? false) && (bool)($result['data']['synced'] ?? false),
                'warning' => ($result['success'] ?? false) ? null : ($result['error'] ?? 'resync failed'),
            ];
        } catch (\Throwable $e) {
            return ['synced' => false, 'warning' => $e->getMessage()];
        }
    }

    private function webmailListsAvailable(\PDO $db): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                      AND table_name IN ('webmail_blocked_senders', 'webmail_safe_senders')";
            return (int)$db->query($sql)->fetchColumn() >= 2;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function emailDomain(string $email): ?string
    {
        $parts = explode('@', $email);
        return count($parts) === 2 ? strtolower($parts[1]) : null;
    }

    // ==================== ATTACHMENT POLICY (DB, read) ====================

    public function listAttachmentPolicy(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        try {
            $db = $this->container->getDatabase();
            $rows = $db->query('SELECT * FROM mail_security_attachment_policy ORDER BY extension ASC')
                ->fetchAll(\PDO::FETCH_ASSOC);
            return Response::success(['policies' => $rows]);
        } catch (\Throwable $e) {
            return Response::success(['policies' => []]);
        }
    }

    public function addAttachmentPolicy(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $extension = ltrim(strtolower(trim((string)$request->input('extension', ''))), '.');
        $listType = $request->input('list_type', 'block');
        $action = $request->input('action', 'quarantine');

        if (!preg_match('/^[a-z0-9]{1,16}$/', $extension)) {
            return Response::error('extension must be 1-16 letters/digits (e.g. "exe")');
        }
        if (!in_array($listType, ['allow', 'block'], true)) {
            return Response::error("list_type must be 'allow' or 'block'");
        }
        if (!in_array($action, ['reject', 'quarantine', 'warn'], true)) {
            return Response::error("action must be 'reject', 'quarantine' or 'warn'");
        }

        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare(
                'INSERT INTO mail_security_attachment_policy (extension, list_type, action)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE list_type = VALUES(list_type), action = VALUES(action)'
            );
            $stmt->execute([$extension, $listType, $action]);
            $this->logAction('mailsec.attachment_add', $extension, 'success', ['list_type' => $listType, 'action' => $action]);
            $this->syncMapsBestEffort();
            return Response::success(['extension' => $extension], 'Attachment policy saved');
        } catch (\Throwable $e) {
            return Response::error('Failed to save policy: ' . $e->getMessage());
        }
    }

    public function deleteAttachmentPolicy(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $id = (int)$request->input('id', 0);
        if ($id <= 0) {
            return Response::error('id is required');
        }
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare('DELETE FROM mail_security_attachment_policy WHERE id = ?');
            $stmt->execute([$id]);
            $this->logAction('mailsec.attachment_delete', (string)$id, 'success');
            $this->syncMapsBestEffort();
            return Response::success(['deleted' => $stmt->rowCount()], 'Attachment policy removed');
        } catch (\Throwable $e) {
            return Response::error('Failed to remove policy: ' . $e->getMessage());
        }
    }

    // ==================== EMAIL AUTH (SPF / DKIM / DMARC) ====================

    /**
     * Live SPF/DKIM/DMARC status across all mail domains. Read-only.
     */
    public function authStatus(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        try {
            $db = $this->container->getDatabase();
            $hasTable = (int)$db->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'mail_domains'"
            )->fetchColumn();
            if ($hasTable < 1) {
                return Response::success(['domains' => [], 'available' => false], 'No mail domains configured');
            }
            $domains = $db->query('SELECT domain FROM mail_domains ORDER BY domain LIMIT 250')
                ->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return Response::success(['domains' => [], 'available' => false]);
        }

        if (empty($domains)) {
            return Response::success(['domains' => [], 'available' => true]);
        }

        $result = $this->agent->execute('mailsec.authStatus', ['domains' => $domains], $this->getActor());
        if (!($result['success'] ?? false)) {
            return Response::error($result['error'] ?? 'Auth status lookup failed');
        }
        return Response::success(array_merge($result['data'], ['available' => true]));
    }

    // ==================== SECURITY SCORE (V3) ====================
    // Per-domain 0-100 posture score, fully derived from data we already have:
    //   - Authentication hardening (60 pts): SPF + DKIM + DMARC, reusing the same
    //     live DNS classification the Auth tab shows (agent mailsec.authStatus).
    //   - Inbound hygiene (40 pts): spam / phishing / malware exposure over the
    //     selected window, from mail_security_events (domain = recipient domain).
    // Read-only: no engine, agent-config or schema changes.

    public function securityScore(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $days = (int)$request->getQuery('days', 30);
        if ($days < 1) {
            $days = 1;
        }
        if ($days > 365) {
            $days = 365;
        }

        try {
            $db = $this->container->getDatabase();
            $hasTable = (int)$db->query(
                "SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = 'mail_domains'"
            )->fetchColumn();
            if ($hasTable < 1) {
                return Response::success(['domains' => [], 'overall' => null, 'available' => false, 'days' => $days], 'No mail domains configured');
            }
            $domains = $db->query('SELECT domain FROM mail_domains ORDER BY domain LIMIT 250')
                ->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable $e) {
            return Response::success(['domains' => [], 'overall' => null, 'available' => false, 'days' => $days]);
        }
        if (empty($domains)) {
            return Response::success(['domains' => [], 'overall' => null, 'available' => true, 'days' => $days]);
        }

        // Per-domain event counts over the window (recipient domain).
        $countsByDomain = [];
        try {
            $stmt = $db->prepare(
                "SELECT domain, event_type, COUNT(*) AS cnt FROM mail_security_events
                 WHERE domain IS NOT NULL AND ts >= (NOW() - INTERVAL ? DAY)
                 GROUP BY domain, event_type"
            );
            $stmt->execute([$days]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $countsByDomain[strtolower((string)$row['domain'])][(string)$row['event_type']] = (int)$row['cnt'];
            }
        } catch (\Throwable $e) {
            // No events table/rows: hygiene falls back to low-data neutral scoring.
        }

        // Live SPF/DKIM/DMARC posture (best-effort; missing => zeroed auth score).
        $authByDomain = [];
        try {
            $res = $this->agent->execute('mailsec.authStatus', ['domains' => $domains], $this->getActor(), 60);
            if (($res['success'] ?? false) && isset($res['data']['domains']) && is_array($res['data']['domains'])) {
                foreach ($res['data']['domains'] as $a) {
                    if (isset($a['domain'])) {
                        $authByDomain[strtolower((string)$a['domain'])] = $a;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Agent unreachable: still return hygiene-only scores.
        }

        $out = [];
        $sum = 0;
        $graded = 0;
        $dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];

        foreach ($domains as $d) {
            $dl = strtolower((string)$d);
            $auth = $authByDomain[$dl] ?? ['spf' => ['status' => 'missing'], 'dkim' => ['status' => 'missing'], 'dmarc' => ['status' => 'missing']];
            $authPts = $this->authScore($auth);
            $hyg = $this->hygieneScore($countsByDomain[$dl] ?? []);
            $total = (int)round($authPts['total'] + $hyg['score']);
            $grade = $this->scoreGrade($total);
            $dist[$grade]++;
            $sum += $total;
            $graded++;

            $out[] = [
                'domain' => $d,
                'score' => $total,
                'grade' => $grade,
                'auth' => [
                    'spf'   => ['points' => $authPts['spf'], 'max' => 20, 'status' => $auth['spf']['status'] ?? 'missing', 'detail' => $auth['spf']['detail'] ?? ''],
                    'dkim'  => ['points' => $authPts['dkim'], 'max' => 20, 'status' => $auth['dkim']['status'] ?? 'missing', 'detail' => $auth['dkim']['detail'] ?? ''],
                    'dmarc' => ['points' => $authPts['dmarc'], 'max' => 20, 'status' => $auth['dmarc']['status'] ?? 'missing', 'detail' => $auth['dmarc']['detail'] ?? ''],
                    'points' => $authPts['total'], 'max' => 60,
                ],
                'hygiene' => [
                    'points' => $hyg['score'], 'max' => 40, 'volume' => $hyg['total'], 'low_data' => $hyg['low_data'],
                    'spam_rate' => $hyg['spam_rate'], 'phish_rate' => $hyg['phish_rate'], 'virus_rate' => $hyg['virus_rate'],
                ],
                'recommendations' => $this->scoreRecommendations($auth, $authPts, $hyg),
            ];
        }

        // Worst-first so the domains that need attention surface at the top.
        usort($out, fn($a, $b) => $a['score'] <=> $b['score']);

        $overall = $graded ? (int)round($sum / $graded) : null;
        return Response::success([
            'available' => true,
            'days' => $days,
            'overall' => $overall === null ? null : ['score' => $overall, 'grade' => $this->scoreGrade($overall)],
            'distribution' => $dist,
            'domains' => $out,
        ]);
    }

    /**
     * Authentication subscore (0-60): SPF (0-20) + DKIM (0-20) + DMARC (0-20),
     * derived from the same record classification the Auth tab shows.
     */
    private function authScore(array $auth): array
    {
        $spfVal   = (string)($auth['spf']['value'] ?? '');
        $dkimStat = (string)($auth['dkim']['status'] ?? 'missing');
        $dmarcVal = (string)($auth['dmarc']['value'] ?? '');

        if ($spfVal === '') {
            $spf = 0;
        } elseif (preg_match('/-all\b/', $spfVal)) {
            $spf = 20;
        } elseif (preg_match('/\+all\b/', $spfVal)) {
            $spf = 4;
        } else {
            $spf = 12; // ~all / ?all / no explicit all mechanism
        }

        if ($dkimStat === 'ok') {
            $dkim = 20;
        } elseif ($dkimStat === 'warn') {
            $dkim = 8; // key generated but not yet published
        } else {
            $dkim = 0;
        }

        if ($dmarcVal === '') {
            $dmarc = 0;
        } elseif (preg_match('/\bp\s*=\s*reject\b/i', $dmarcVal)) {
            $dmarc = 20;
        } elseif (preg_match('/\bp\s*=\s*quarantine\b/i', $dmarcVal)) {
            $dmarc = 16;
        } elseif (preg_match('/\bp\s*=\s*none\b/i', $dmarcVal)) {
            $dmarc = 8;
        } else {
            $dmarc = 6;
        }

        return ['spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc, 'total' => $spf + $dkim + $dmarc];
    }

    /**
     * Inbound-hygiene subscore (0-40) from event rates over the window. Spam is
     * treated as background noise (light penalty); phishing and especially
     * malware are weighted heavily. Quiet domains (< 20 msgs) get a neutral,
     * flagged score so they are neither rewarded nor punished for low volume.
     */
    private function hygieneScore(array $counts): array
    {
        $clean  = (int)($counts['clean'] ?? 0);
        $spam   = (int)($counts['spam'] ?? 0);
        $reject = (int)($counts['reject'] ?? 0);
        $quar   = (int)($counts['quarantine'] ?? 0);
        $phish  = (int)($counts['phish'] ?? 0);
        $virus  = (int)($counts['virus'] ?? 0);

        $total = $clean + $spam + $reject + $quar + $phish + $virus;

        if ($total < 20) {
            return ['score' => 34, 'total' => $total, 'low_data' => true,
                'spam_rate' => 0.0, 'phish_rate' => 0.0, 'virus_rate' => 0.0];
        }

        $spamRate  = ($spam + $reject) / $total;
        $phishRate = $phish / $total;
        $virusRate = $virus / $total;

        $score = 40.0;
        $score -= min(8.0, $spamRate * 16);    // spam: light
        $score -= min(16.0, $phishRate * 80);  // phishing: serious
        $score -= min(16.0, $virusRate * 120); // malware: most serious
        $score = max(0, (int)round($score));

        return ['score' => $score, 'total' => $total, 'low_data' => false,
            'spam_rate' => round($spamRate, 3), 'phish_rate' => round($phishRate, 3), 'virus_rate' => round($virusRate, 3)];
    }

    private function scoreGrade(int $score): string
    {
        if ($score >= 90) {
            return 'A';
        }
        if ($score >= 80) {
            return 'B';
        }
        if ($score >= 70) {
            return 'C';
        }
        if ($score >= 60) {
            return 'D';
        }
        return 'F';
    }

    /**
     * Actionable, severity-ranked recommendations from the weakest factors.
     */
    private function scoreRecommendations(array $auth, array $authPts, array $hyg): array
    {
        $recs = [];

        $spfVal = (string)($auth['spf']['value'] ?? '');
        if ($spfVal === '') {
            $recs[] = ['severity' => 'high', 'text' => 'Publish an SPF record for this domain.'];
        } elseif (preg_match('/\+all\b/', $spfVal)) {
            $recs[] = ['severity' => 'high', 'text' => 'SPF uses +all (allows any sender) - change it to a hard fail (-all).'];
        } elseif (($authPts['spf'] ?? 0) < 20) {
            $recs[] = ['severity' => 'medium', 'text' => 'Tighten SPF to a hard fail (-all) once all legitimate senders are verified.'];
        }

        $dkimStat = (string)($auth['dkim']['status'] ?? 'missing');
        if ($dkimStat === 'missing') {
            $recs[] = ['severity' => 'high', 'text' => 'Enable DKIM signing for this domain.'];
        } elseif ($dkimStat === 'warn') {
            $recs[] = ['severity' => 'medium', 'text' => 'DKIM key is generated but not published in DNS - publish the public key.'];
        }

        $dmarcVal = (string)($auth['dmarc']['value'] ?? '');
        if ($dmarcVal === '') {
            $recs[] = ['severity' => 'high', 'text' => 'Publish a DMARC record (start with p=none to monitor, then enforce).'];
        } elseif (preg_match('/\bp\s*=\s*none\b/i', $dmarcVal)) {
            $recs[] = ['severity' => 'medium', 'text' => 'Move DMARC from p=none to p=quarantine or p=reject to enforce.'];
        }

        if (!($hyg['low_data'] ?? false)) {
            if (($hyg['virus_rate'] ?? 0) > 0) {
                $recs[] = ['severity' => 'high', 'text' => 'Malware was detected in inbound mail - keep ClamAV signatures current and review the attachment policy.'];
            }
            if (($hyg['phish_rate'] ?? 0) >= 0.05) {
                $recs[] = ['severity' => 'medium', 'text' => 'Elevated phishing is targeting this domain - consider stricter mail-flow rules and user awareness.'];
            }
        }

        if (empty($recs)) {
            $recs[] = ['severity' => 'ok', 'text' => 'Authentication is hardened and inbound hygiene looks healthy.'];
        }
        return $recs;
    }

    // ==================== THREAT CENTER (V3) ====================
    // Central, severity-bucketed view of detected threats over a window, derived
    // from mail_security_events. Threats are classified into categories (malware,
    // phishing, impersonation, spam, policy, auth) by event_type + the emitting
    // Rspamd symbol; each category maps to a fixed severity. Read-only.

    /** Category labels + fixed severity, in display order. */
    private const THREAT_CATS = [
        'malware'       => ['label' => 'Malware',        'severity' => 'critical'],
        'phishing'      => ['label' => 'Phishing',       'severity' => 'high'],
        'impersonation' => ['label' => 'Impersonation',  'severity' => 'high'],
        'spam'          => ['label' => 'Spam',           'severity' => 'medium'],
        'policy'        => ['label' => 'Policy',          'severity' => 'medium'],
        'auth'          => ['label' => 'Auth failures',   'severity' => 'low'],
    ];

    public function threatCenter(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $days = (int)$request->getQuery('days', 30);
        if ($days < 1) {
            $days = 1;
        }
        if ($days > 365) {
            $days = 365;
        }

        // Single source of truth for category classification (event_type + symbol).
        $cat = "CASE
            WHEN event_type = 'virus' THEN 'malware'
            WHEN event_type IN ('spf_fail','dkim_fail','dmarc_fail') THEN 'auth'
            WHEN UPPER(COALESCE(symbol,'')) REGEXP 'CEO_SPOOF|INTERNAL_SPOOF|LOOKALIKE' THEN 'impersonation'
            WHEN event_type = 'phish' THEN 'phishing'
            WHEN UPPER(COALESCE(symbol,'')) REGEXP 'ATTACH|BANNED_ATTACHMENT|MAILSEC_RULES|MAILSEC_GEOIP' OR event_type = 'policy' THEN 'policy'
            WHEN event_type IN ('spam','reject','quarantine') THEN 'spam'
            ELSE NULL END";

        $empty = [
            'available' => true, 'days' => $days,
            'categories' => [], 'severity' => ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0],
            'total' => 0, 'prev_total' => 0, 'daily' => [], 'recent' => [],
            'top_sources' => [], 'top_targets' => [],
        ];

        try {
            $db = $this->container->getDatabase();

            // Category counts for a window [now-d0, now-d1). d1=0 => current period.
            $catCounts = function (int $d0, int $d1) use ($db, $cat): array {
                $where = 'ts >= (NOW() - INTERVAL ? DAY)';
                $args = [$d0];
                if ($d1 > 0) {
                    $where .= ' AND ts < (NOW() - INTERVAL ? DAY)';
                    $args[] = $d1;
                }
                $stmt = $db->prepare(
                    "SELECT cat, COUNT(*) cnt FROM (SELECT {$cat} AS cat FROM mail_security_events WHERE {$where}) x
                     WHERE cat IS NOT NULL GROUP BY cat"
                );
                $stmt->execute($args);
                $out = [];
                foreach ($stmt->fetchAll(\PDO::FETCH_KEY_PAIR) as $k => $v) {
                    $out[(string)$k] = (int)$v;
                }
                return $out;
            };

            $cur = $catCounts($days, 0);
            $prev = $catCounts($days * 2, $days);

            $categories = [];
            $severity = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
            $total = 0;
            foreach (self::THREAT_CATS as $key => $meta) {
                $count = $cur[$key] ?? 0;
                $categories[] = [
                    'key' => $key,
                    'label' => $meta['label'],
                    'severity' => $meta['severity'],
                    'count' => $count,
                    'prev' => $prev[$key] ?? 0,
                ];
                $severity[$meta['severity']] += $count;
                $total += $count;
            }

            // Daily timeline, pivoted from the same classification.
            $dailyStmt = $db->prepare(
                "SELECT day,
                    SUM(cat='malware') malware, SUM(cat='phishing') phishing, SUM(cat='impersonation') impersonation,
                    SUM(cat='spam') spam, SUM(cat='policy') policy, SUM(cat='auth') auth, COUNT(*) total
                 FROM (SELECT DATE(ts) day, {$cat} cat FROM mail_security_events WHERE ts >= (NOW() - INTERVAL ? DAY)) x
                 WHERE cat IS NOT NULL GROUP BY day ORDER BY day ASC"
            );
            $dailyStmt->execute([$days]);
            $daily = array_map(static function (array $r): array {
                return [
                    'day' => $r['day'],
                    'malware' => (int)$r['malware'], 'phishing' => (int)$r['phishing'],
                    'impersonation' => (int)$r['impersonation'], 'spam' => (int)$r['spam'],
                    'policy' => (int)$r['policy'], 'auth' => (int)$r['auth'], 'total' => (int)$r['total'],
                ];
            }, $dailyStmt->fetchAll(\PDO::FETCH_ASSOC));

            // Recent threat events (drill-down), newest first.
            $recentStmt = $db->prepare(
                "SELECT * FROM (
                    SELECT ts, event_type, sender, recipient, domain, score, symbol, {$cat} cat
                    FROM mail_security_events WHERE ts >= (NOW() - INTERVAL ? DAY)
                 ) x WHERE cat IS NOT NULL ORDER BY ts DESC LIMIT 150"
            );
            $recentStmt->execute([$days]);
            $recent = array_map(function (array $r): array {
                $catKey = (string)$r['cat'];
                return [
                    'ts' => $r['ts'],
                    'category' => $catKey,
                    'severity' => self::THREAT_CATS[$catKey]['severity'] ?? 'low',
                    'sender' => $r['sender'],
                    'recipient' => $r['recipient'],
                    'domain' => $r['domain'],
                    'score' => $r['score'] !== null ? (float)$r['score'] : null,
                    'symbol' => $r['symbol'],
                    'event_type' => $r['event_type'],
                ];
            }, $recentStmt->fetchAll(\PDO::FETCH_ASSOC));

            // Top threat sources + most-targeted recipient domains.
            $topStmt = function (string $col) use ($db, $cat): array {
                $stmt = $db->prepare(
                    "SELECT {$col} AS k, COUNT(*) cnt FROM (
                        SELECT {$col}, {$cat} cat FROM mail_security_events
                        WHERE ts >= (NOW() - INTERVAL ? DAY) AND {$col} IS NOT NULL AND {$col} <> ''
                     ) x WHERE cat IS NOT NULL GROUP BY {$col} ORDER BY cnt DESC LIMIT 10"
                );
                return $stmt;
            };
            $srcStmt = $topStmt('sender');
            $srcStmt->execute([$days]);
            $topSources = array_map(static fn($r) => ['value' => $r['k'], 'count' => (int)$r['cnt']], $srcStmt->fetchAll(\PDO::FETCH_ASSOC));

            $tgtStmt = $topStmt('domain');
            $tgtStmt->execute([$days]);
            $topTargets = array_map(static fn($r) => ['value' => $r['k'], 'count' => (int)$r['cnt']], $tgtStmt->fetchAll(\PDO::FETCH_ASSOC));

            return Response::success([
                'available' => true,
                'days' => $days,
                'categories' => $categories,
                'severity' => $severity,
                'total' => $total,
                'prev_total' => array_sum($prev),
                'daily' => $daily,
                'recent' => $recent,
                'top_sources' => $topSources,
                'top_targets' => $topTargets,
            ]);
        } catch (\Throwable $e) {
            return Response::success($empty);
        }
    }

    // ==================== AI PHISHING ANALYSIS (V3) ====================
    // On-demand phishing/threat analysis of a single message, reusing the panel
    // AI Helper's OpenAI plumbing (key + model from ai_helper_settings). The
    // model returns a structured verdict + risk score + indicators + advice.
    // The untrusted email text is sent via AIHelperService::chat(), which - unlike
    // analyzeIssue() - performs no config-file reading or command execution.

    public function analyzePhishing(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }

        $subject = trim((string)$request->input('subject', ''));
        $sender  = trim((string)$request->input('sender', ''));
        $content = (string)$request->input('content', '');
        $quarantineId = (int)$request->input('quarantine_id', 0);

        // Optionally seed from a quarantined message's stored metadata.
        if ($quarantineId > 0) {
            try {
                $db = $this->container->getDatabase();
                $stmt = $db->prepare('SELECT sender, recipient, subject, reason, spam_score FROM mail_quarantine WHERE id = ? LIMIT 1');
                $stmt->execute([$quarantineId]);
                if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    if ($subject === '') {
                        $subject = (string)$row['subject'];
                    }
                    if ($sender === '') {
                        $sender = (string)$row['sender'];
                    }
                    if ($content === '') {
                        $content = "Quarantined message metadata (no body available):\n"
                            . "Subject: {$row['subject']}\nFrom: {$row['sender']}\nTo: {$row['recipient']}\n"
                            . "Engine reason: {$row['reason']}\nSpam score: {$row['spam_score']}";
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal; fall through to content validation.
            }
        }

        $content = trim($content);
        if ($content === '' && $subject === '') {
            return Response::error('Provide message content (or at least a subject/sender) to analyze.');
        }
        if (strlen($content) > 12000) {
            $content = substr($content, 0, 12000) . "\n\n... [truncated] ...";
        }

        // Surface links explicitly - they are the strongest phishing signal.
        $urls = [];
        if (preg_match_all('~https?://[^\s"\'<>)\]]+~i', $subject . "\n" . $content, $m)) {
            $urls = array_slice(array_values(array_unique($m[0])), 0, 40);
        }

        $system = "You are an expert email-security analyst. Analyze the message for phishing, "
            . "spoofing/impersonation, malware lures, scams and social engineering. Weigh the sender, "
            . "headers, language, urgency cues, and especially links (lookalike/typosquat domains, "
            . "display-name vs. link mismatch, URL shorteners, raw-IP URLs, credential-harvesting pages).\n\n"
            . "Respond with ONLY a JSON object - no prose, no code fences - using exactly this shape:\n"
            . "{\n"
            . "  \"verdict\": \"phishing\" | \"suspicious\" | \"likely_safe\",\n"
            . "  \"score\": <integer 0-100, higher = more dangerous>,\n"
            . "  \"confidence\": \"low\" | \"medium\" | \"high\",\n"
            . "  \"summary\": <one or two plain-English sentences>,\n"
            . "  \"indicators\": [ { \"type\": <short label>, \"detail\": <what was found>, \"severity\": \"low\"|\"medium\"|\"high\" } ],\n"
            . "  \"recommended_action\": \"deliver\" | \"quarantine\" | \"reject\" | \"educate_user\",\n"
            . "  \"recommendations\": [ <short actionable string>, ... ]\n"
            . "}";

        $userParts = [];
        if ($subject !== '') {
            $userParts[] = "Subject: {$subject}";
        }
        if ($sender !== '') {
            $userParts[] = "From: {$sender}";
        }
        if (!empty($urls)) {
            $userParts[] = "Extracted URLs:\n- " . implode("\n- ", $urls);
        }
        $userParts[] = "Message:\n" . ($content !== '' ? $content : '(no body provided)');

        try {
            $ai = $this->container->get(\VpsAdmin\Api\Services\AIHelperService::class);
        } catch (\Throwable $e) {
            return Response::error('AI Helper is not available on this panel.');
        }

        $result = $ai->chat([
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => implode("\n\n", $userParts)],
        ], null, 900, 0.1);

        if (!($result['success'] ?? false)) {
            return Response::error($result['error'] ?? 'AI analysis failed');
        }

        $analysis = $this->parsePhishingJson((string)($result['content'] ?? ''));
        $analysis['url_count'] = count($urls);

        $this->logAction('mailsec.ai_analyze', $quarantineId > 0 ? "quarantine:{$quarantineId}" : 'adhoc', 'success', [
            'verdict' => $analysis['verdict'] ?? 'unknown',
            'score' => $analysis['score'] ?? null,
        ]);

        return Response::success([
            'analysis' => $analysis,
            'model' => $result['model'] ?? null,
            'usage' => $result['usage'] ?? null,
        ]);
    }

    /**
     * Defensively parse the model's JSON verdict; falls back to a raw-summary
     * shape when the model didn't return clean JSON.
     */
    private function parsePhishingJson(string $raw): array
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        if (preg_match('/\{.*\}/s', $text, $m)) {
            $text = $m[0];
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            return [
                'verdict' => 'unknown', 'score' => null, 'confidence' => 'low',
                'summary' => trim($raw) !== '' ? trim($raw) : 'The analyzer did not return a parseable result.',
                'indicators' => [], 'recommended_action' => null, 'recommendations' => [],
                'parse_error' => true,
            ];
        }

        $verdict = strtolower((string)($data['verdict'] ?? 'unknown'));
        if (!in_array($verdict, ['phishing', 'suspicious', 'likely_safe'], true)) {
            $verdict = 'unknown';
        }
        $score = $data['score'] ?? null;
        if ($score !== null) {
            $score = max(0, min(100, (int)$score));
        }
        $indicators = [];
        foreach ((array)($data['indicators'] ?? []) as $ind) {
            if (!is_array($ind)) {
                continue;
            }
            $indicators[] = [
                'type' => (string)($ind['type'] ?? 'indicator'),
                'detail' => (string)($ind['detail'] ?? ''),
                'severity' => in_array(($ind['severity'] ?? ''), ['low', 'medium', 'high'], true) ? $ind['severity'] : 'medium',
            ];
        }
        $recs = [];
        foreach ((array)($data['recommendations'] ?? []) as $r) {
            if (is_string($r) && trim($r) !== '') {
                $recs[] = trim($r);
            }
        }

        return [
            'verdict' => $verdict,
            'score' => $score,
            'confidence' => in_array(($data['confidence'] ?? ''), ['low', 'medium', 'high'], true) ? $data['confidence'] : 'medium',
            'summary' => (string)($data['summary'] ?? ''),
            'indicators' => $indicators,
            'recommended_action' => $data['recommended_action'] ?? null,
            'recommendations' => $recs,
        ];
    }

    // ==================== VIRUSTOTAL (V3, on-demand) ====================
    // On-demand URL / file-hash reputation via the VirusTotal v3 API, cached in
    // mail_security_vt_cache to respect the free-tier limits (4/min, 500/day).
    // The panel calls VirusTotal directly (same as the AI Helper -> OpenAI), so
    // there is no engine/agent involvement and zero impact on the live mail path.

    public function getVirustotalConfig(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $key = (string)($this->getSetting('virustotal_api_key') ?? '');
        $ttl = (int)($this->getSetting('virustotal_cache_ttl') ?? 24);
        if ($ttl < 1) {
            $ttl = 24;
        }
        return Response::success([
            'configured' => $key !== '',
            'hint' => $key !== '' ? '***' . substr($key, -4) : '',
            'cache_ttl_hours' => $ttl,
        ]);
    }

    public function saveVirustotalConfig(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $changed = [];

        $apiKey = $request->input('api_key', null);
        if ($apiKey !== null) {
            $apiKey = trim((string)$apiKey);
            // Ignore the masked placeholder so a save without retyping keeps the key.
            if (strpos($apiKey, '***') !== 0) {
                $this->putSetting('virustotal_api_key', $apiKey);
                $changed[] = 'api_key';
            }
        }

        if ($request->input('cache_ttl_hours', null) !== null) {
            $ttl = max(1, min(720, (int)$request->input('cache_ttl_hours')));
            $this->putSetting('virustotal_cache_ttl', (string)$ttl);
            $changed[] = 'cache_ttl_hours';
        }

        if (empty($changed)) {
            return Response::error('Nothing to update.');
        }
        $this->logAction('mailsec.virustotal_config', 'global', 'success', ['changed' => $changed]);
        return Response::success(['updated' => $changed], 'VirusTotal settings saved');
    }

    public function checkVirustotal(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $resource = trim((string)$request->input('resource', ''));
        $type = strtolower((string)$request->input('type', 'auto'));
        $force = (bool)$request->input('force', false);

        if ($resource === '') {
            return Response::error('Enter a URL or file hash to check.');
        }
        if (strlen($resource) > 2048) {
            return Response::error('Resource is too long.');
        }
        if ($type === 'auto' || !in_array($type, ['url', 'file'], true)) {
            $type = $this->vtDetectType($resource);
        }
        if ($type === '') {
            return Response::error('Could not recognize the input. Enter a full URL or an MD5 / SHA-1 / SHA-256 hash.');
        }

        $apiKey = (string)($this->getSetting('virustotal_api_key') ?? '');
        if ($apiKey === '') {
            return Response::error('VirusTotal API key not configured. Add it in the VirusTotal tab.');
        }
        $ttl = (int)($this->getSetting('virustotal_cache_ttl') ?? 24);
        if ($ttl < 1) {
            $ttl = 24;
        }

        // Normalize the resource so cache keys are stable.
        if ($type === 'url') {
            $effective = preg_match('~^https?://~i', $resource) ? $resource : 'http://' . $resource;
        } else {
            $effective = strtolower($resource);
        }
        $hash = hash('sha256', $type . '|' . $effective);

        $db = $this->container->getDatabase();

        if (!$force) {
            try {
                $stmt = $db->prepare(
                    "SELECT resource_type, resource, verdict, malicious, suspicious, harmless, undetected, total, permalink, checked_at
                     FROM mail_security_vt_cache
                     WHERE resource_type = ? AND resource_hash = ? AND checked_at >= (NOW() - INTERVAL ? HOUR)
                     LIMIT 1"
                );
                $stmt->execute([$type, $hash, $ttl]);
                if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                    return Response::success(['result' => $this->vtRowToResult($row, true)]);
                }
            } catch (\Throwable $e) {
                // fall through to a live query
            }
        }

        $base = 'https://www.virustotal.com/api/v3/';
        if ($type === 'file') {
            $resp = $this->vtHttp('GET', $base . 'files/' . rawurlencode($effective), $apiKey);
            $gui = 'https://www.virustotal.com/gui/file/' . $effective;
        } else {
            $id = $this->vtUrlId($effective);
            $resp = $this->vtHttp('GET', $base . 'urls/' . $id, $apiKey);
            $gui = 'https://www.virustotal.com/gui/url/' . $id;
        }

        if ($resp['error'] !== '') {
            return Response::error('VirusTotal request failed: ' . $resp['error']);
        }
        $code = $resp['code'];
        if ($code === 401 || $code === 403) {
            return Response::error('VirusTotal rejected the API key (check it in the VirusTotal tab).');
        }
        if ($code === 429) {
            return Response::error('VirusTotal rate limit reached (free tier: 4/min, 500/day). Try again shortly.');
        }

        if ($code === 404) {
            // Not previously seen. For URLs, submit for analysis (uses 1 quota).
            if ($type === 'url') {
                $this->vtHttp('POST', $base . 'urls', $apiKey, ['url' => $effective]);
                return Response::success(['result' => [
                    'resource' => $effective, 'resource_type' => 'url', 'verdict' => 'pending',
                    'malicious' => 0, 'suspicious' => 0, 'harmless' => 0, 'undetected' => 0, 'total' => 0,
                    'permalink' => $gui, 'cached' => false,
                    'message' => 'This URL was not in VirusTotal yet; it has been submitted for analysis. Re-check in ~30 seconds.',
                ]]);
            }
            return Response::success(['result' => [
                'resource' => $effective, 'resource_type' => 'file', 'verdict' => 'unknown',
                'malicious' => 0, 'suspicious' => 0, 'harmless' => 0, 'undetected' => 0, 'total' => 0,
                'permalink' => $gui, 'cached' => false,
                'message' => 'This file hash is not known to VirusTotal.',
            ]]);
        }

        if ($code !== 200) {
            return Response::error('VirusTotal returned HTTP ' . $code . '.');
        }

        $data = json_decode((string)$resp['body'], true);
        $stats = $data['data']['attributes']['last_analysis_stats'] ?? null;
        if (!is_array($stats)) {
            return Response::error('VirusTotal response did not include analysis stats.');
        }

        $malicious  = (int)($stats['malicious'] ?? 0);
        $suspicious = (int)($stats['suspicious'] ?? 0);
        $harmless   = (int)($stats['harmless'] ?? 0);
        $undetected = (int)($stats['undetected'] ?? 0);
        $timeout    = (int)($stats['timeout'] ?? 0);
        $total = $malicious + $suspicious + $harmless + $undetected + $timeout;
        $verdict = $this->vtVerdict($malicious, $suspicious, $total);

        try {
            $stmt = $db->prepare(
                "INSERT INTO mail_security_vt_cache
                    (resource_type, resource, resource_hash, verdict, malicious, suspicious, harmless, undetected, total, permalink, raw)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE verdict = VALUES(verdict), malicious = VALUES(malicious),
                    suspicious = VALUES(suspicious), harmless = VALUES(harmless), undetected = VALUES(undetected),
                    total = VALUES(total), permalink = VALUES(permalink), raw = VALUES(raw), checked_at = NOW()"
            );
            $stmt->execute([
                $type, substr($effective, 0, 2048), $hash, $verdict,
                $malicious, $suspicious, $harmless, $undetected, $total,
                substr($gui, 0, 512), json_encode($stats),
            ]);
        } catch (\Throwable $e) {
            // Cache write is best-effort; still return the live result.
        }

        $this->logAction('mailsec.virustotal_check', $type . ':' . substr($effective, 0, 180), 'success', [
            'verdict' => $verdict, 'malicious' => $malicious,
        ]);

        return Response::success(['result' => [
            'resource' => $effective, 'resource_type' => $type, 'verdict' => $verdict,
            'malicious' => $malicious, 'suspicious' => $suspicious, 'harmless' => $harmless,
            'undetected' => $undetected, 'total' => $total, 'permalink' => $gui,
            'cached' => false, 'checked_at' => date('Y-m-d H:i:s'),
        ]]);
    }

    public function listVirustotalRecent(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        try {
            $db = $this->container->getDatabase();
            $rows = $db->query(
                "SELECT resource_type, resource, verdict, malicious, suspicious, harmless, undetected, total, permalink, checked_at
                 FROM mail_security_vt_cache ORDER BY checked_at DESC LIMIT 20"
            )->fetchAll(\PDO::FETCH_ASSOC);
            return Response::success(['items' => array_map(fn($r) => $this->vtRowToResult($r, true), $rows)]);
        } catch (\Throwable $e) {
            return Response::success(['items' => []]);
        }
    }

    private function vtDetectType(string $r): string
    {
        if (preg_match('~^(?:[a-f0-9]{32}|[a-f0-9]{40}|[a-f0-9]{64})$~i', $r)) {
            return 'file';
        }
        if (preg_match('~^https?://~i', $r) || preg_match('~^[a-z0-9.-]+\.[a-z]{2,}(?:[:/].*)?$~i', $r)) {
            return 'url';
        }
        return '';
    }

    private function vtUrlId(string $url): string
    {
        return rtrim(strtr(base64_encode($url), '+/', '-_'), '=');
    }

    /**
     * Map engine counts to a verdict. A single malicious detection is a strong
     * signal, but a lone "suspicious" vote (often a predictive/heuristic engine
     * like Bfore.Ai PreCrime) is treated as noise: suspicious needs >= 2 votes
     * to escalate, so legitimate sites are not flagged over one false positive.
     */
    private function vtVerdict(int $malicious, int $suspicious, int $total): string
    {
        if ($malicious >= 1) {
            return 'malicious';
        }
        if ($suspicious >= 2) {
            return 'suspicious';
        }
        if ($total > 0) {
            return 'harmless';
        }
        return 'unknown';
    }

    private function vtRowToResult(array $row, bool $cached): array
    {
        $malicious  = (int)$row['malicious'];
        $suspicious = (int)$row['suspicious'];
        $harmless   = (int)$row['harmless'];
        $undetected = (int)$row['undetected'];
        $total      = (int)$row['total'];

        // Recompute the verdict from the raw counts so the current thresholds
        // apply even to rows that were cached under older rules.
        $verdict = $total > 0
            ? $this->vtVerdict($malicious, $suspicious, $total)
            : (string)($row['verdict'] ?? 'unknown');

        return [
            'resource' => $row['resource'],
            'resource_type' => $row['resource_type'],
            'verdict' => $verdict,
            'malicious' => $malicious,
            'suspicious' => $suspicious,
            'harmless' => $harmless,
            'undetected' => $undetected,
            'total' => $total,
            'permalink' => $row['permalink'] ?? null,
            'checked_at' => $row['checked_at'] ?? null,
            'cached' => $cached,
        ];
    }

    /**
     * Minimal cURL wrapper for the VirusTotal v3 API (x-apikey auth).
     */
    private function vtHttp(string $method, string $url, string $apiKey, ?array $postFields = null): array
    {
        $headers = ['x-apikey: ' . $apiKey, 'Accept: application/json'];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = http_build_query($postFields ?? []);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;

        $ch = curl_init($url);
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return ['code' => $code, 'body' => $body === false ? '' : $body, 'error' => $error];
    }

    // ==================== QUARANTINE (DB, read) ====================

    public function listQuarantine(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $pagination = $this->getPagination($request);
        $status = $request->getQuery('status', 'quarantined');
        $search = trim((string)$request->getQuery('search', ''));

        try {
            $db = $this->container->getDatabase();
            $where = ['status = ?'];
            $args = [$status];
            if ($search !== '') {
                $where[] = '(sender LIKE ? OR recipient LIKE ? OR subject LIKE ?)';
                $like = '%' . $search . '%';
                array_push($args, $like, $like, $like);
            }
            $whereSql = 'WHERE ' . implode(' AND ', $where);
            $offset = ($pagination['page'] - 1) * $pagination['per_page'];

            $total = (int)(function () use ($db, $whereSql, $args) {
                $stmt = $db->prepare("SELECT COUNT(*) FROM mail_quarantine {$whereSql}");
                $stmt->execute($args);
                return $stmt->fetchColumn();
            })();

            $stmt = $db->prepare(
                "SELECT id, message_id, sender, recipient, subject, spam_score, reason, status, created_at
                 FROM mail_quarantine {$whereSql}
                 ORDER BY created_at DESC LIMIT {$pagination['per_page']} OFFSET {$offset}"
            );
            $stmt->execute($args);

            return Response::success([
                'items' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
                'total' => $total,
                'page' => $pagination['page'],
                'per_page' => $pagination['per_page'],
            ]);
        } catch (\Throwable $e) {
            return Response::success(['items' => [], 'total' => 0, 'page' => 1, 'per_page' => $pagination['per_page']]);
        }
    }

    /**
     * Release a quarantined message for normal delivery.
     */
    public function releaseQuarantine(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $id = (int)$request->input('id', 0);
        if ($id <= 0) {
            return Response::error('id is required');
        }

        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare(
                'SELECT id, sender, recipient, spool_path, status FROM mail_quarantine WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return Response::error('Quarantine message not found');
            }
            if ($row['status'] !== 'quarantined') {
                return Response::error('Message is not in quarantined status');
            }

            $result = $this->agent->execute('mailsec.releaseQuarantine', [
                'spool_path' => $row['spool_path'],
                'recipient' => $row['recipient'],
                'sender' => $row['sender'],
            ], $this->getActor());

            if (!($result['success'] ?? false)) {
                $this->logAction('mailsec.quarantine_release', (string)$id, 'failed', ['error' => $result['error'] ?? '']);
                return Response::error($result['error'] ?? 'Release failed');
            }

            $upd = $db->prepare(
                'UPDATE mail_quarantine SET status = ?, released_at = NOW(), released_by = ? WHERE id = ? AND status = ?'
            );
            $upd->execute(['released', $this->getActor(), $id, 'quarantined']);

            $this->logAction('mailsec.quarantine_release', (string)$id, 'success', ['recipient' => $row['recipient']]);
            return Response::success(['id' => $id, 'status' => 'released'], 'Message released for delivery');
        } catch (\Throwable $e) {
            return Response::error('Release failed: ' . $e->getMessage());
        }
    }

    /**
     * Permanently delete a quarantined message (spool file + DB status).
     */
    public function deleteQuarantine(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $id = (int)$request->input('id', 0);
        if ($id <= 0) {
            return Response::error('id is required');
        }

        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare(
                'SELECT id, spool_path, status FROM mail_quarantine WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                return Response::error('Quarantine message not found');
            }
            if ($row['status'] !== 'quarantined') {
                return Response::error('Message is not in quarantined status');
            }

            $result = $this->agent->execute('mailsec.deleteQuarantineFile', [
                'spool_path' => $row['spool_path'],
            ], $this->getActor());

            if (!($result['success'] ?? false)) {
                $this->logAction('mailsec.quarantine_delete', (string)$id, 'failed', ['error' => $result['error'] ?? '']);
                return Response::error($result['error'] ?? 'Delete failed');
            }

            $upd = $db->prepare('UPDATE mail_quarantine SET status = ? WHERE id = ? AND status = ?');
            $upd->execute(['deleted', $id, 'quarantined']);

            $this->logAction('mailsec.quarantine_delete', (string)$id, 'success');
            return Response::success(['id' => $id, 'status' => 'deleted'], 'Message deleted');
        } catch (\Throwable $e) {
            return Response::error('Delete failed: ' . $e->getMessage());
        }
    }

    /**
     * Run quarantine retention (+ optional digest) on demand via the agent.
     * The same sweep runs daily from cron; this is the "Run cleanup now" button.
     */
    public function runQuarantineMaintenance(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }

        $params = [];
        if ($request->input('dry_run')) {
            $params['dry_run'] = true;
        }

        $result = $this->agent->execute('mailsec.maintainQuarantine', $params, $this->getActor());

        if (!($result['success'] ?? false)) {
            $this->logAction('mailsec.quarantine_maintenance', 'global', 'failed', ['error' => $result['error'] ?? '']);
            return Response::error($result['error'] ?? 'Maintenance failed');
        }

        $this->logAction('mailsec.quarantine_maintenance', 'global', 'success', $result['data'] ?? []);
        return Response::success($result['data'] ?? [], $result['message'] ?? 'Quarantine maintenance complete');
    }

    // ============ SELF-SERVICE QUARANTINE (public, HMAC token-signed) ============
    // End users receive a per-recipient digest (built by quarantine-maintenance.php)
    // with signed links to release/delete/allow their OWN held mail without logging
    // in. The token is an HMAC bound to the message id + recipient + an expiry. The
    // GET renders a confirmation page (so link scanners / client prefetch can never
    // mutate state); the POST performs the action. Both are deliberately OUTSIDE the
    // auth middleware and authorise purely on the token.

    /**
     * GET /api/mailsec-q?token=... - confirmation landing page (no login).
     */
    public function quarantineLanding(Request $request): Response
    {
        $token = (string) $request->getQuery('token', '');
        $row = $this->quarantineVerifyToken($token);
        if ($row === null) {
            return $this->quarantinePage('Link invalid or expired',
                '<p class="lead">This link is invalid or has expired. Held mail can still be reviewed from the admin panel.</p>', 400);
        }
        if (($row['status'] ?? '') !== 'quarantined') {
            return $this->quarantinePage('Already handled',
                '<p class="lead">This message has already been released or removed - no further action is needed.</p>', 200);
        }

        $sender  = htmlspecialchars((string) ($row['sender'] ?? '(unknown)'), ENT_QUOTES, 'UTF-8');
        $subject = htmlspecialchars((string) ($row['subject'] ?? '(no subject)'), ENT_QUOTES, 'UTF-8');
        $rcpt    = htmlspecialchars((string) ($row['recipient'] ?? ''), ENT_QUOTES, 'UTF-8');
        $when    = htmlspecialchars((string) ($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tok     = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');

        $body = '<p class="lead">A message held for <strong>' . $rcpt . '</strong> is waiting for your decision.</p>'
            . '<table class="meta">'
            . '<tr><th>From</th><td>' . $sender . '</td></tr>'
            . '<tr><th>Subject</th><td>' . $subject . '</td></tr>'
            . '<tr><th>Received</th><td>' . $when . '</td></tr>'
            . '</table>'
            . '<form method="post" action="' . self::QUARANTINE_PUBLIC_PATH . '">'
            . '<input type="hidden" name="token" value="' . $tok . '">'
            . '<button class="btn release" name="action" value="release" type="submit">Release (deliver to my inbox)</button>'
            . '<button class="btn allow" name="action" value="allow" type="submit">Release &amp; always allow this sender</button>'
            . '<button class="btn delete" name="action" value="delete" type="submit">Delete permanently</button>'
            . '</form>';

        return $this->quarantinePage('Review held message', $body, 200);
    }

    /**
     * POST /api/mailsec-q - perform release|delete|allow on a held message (no login).
     * Mirrors the admin release/delete paths (same agent calls + audit log) with the
     * recipient as the self-service actor.
     */
    public function quarantineAction(Request $request): Response
    {
        $token  = (string) $request->input('token', '');
        $action = (string) $request->input('action', '');
        if (!in_array($action, ['release', 'delete', 'allow'], true)) {
            return $this->quarantinePage('Unknown action', '<p class="lead">That action is not recognised.</p>', 400);
        }
        $row = $this->quarantineVerifyToken($token);
        if ($row === null) {
            return $this->quarantinePage('Link invalid or expired',
                '<p class="lead">This link is invalid or has expired.</p>', 400);
        }
        if (($row['status'] ?? '') !== 'quarantined') {
            return $this->quarantinePage('Already handled',
                '<p class="lead">This message has already been released or removed.</p>', 200);
        }

        $id    = (int) $row['id'];
        $rcpt  = (string) ($row['recipient'] ?? '');
        $actor = 'self-service:' . ($rcpt !== '' ? $rcpt : 'unknown');

        try {
            $db = $this->container->getDatabase();

            if ($action === 'delete') {
                $res = $this->agent->execute('mailsec.deleteQuarantineFile', ['spool_path' => $row['spool_path']], $actor);
                if (!($res['success'] ?? false)) {
                    return $this->quarantinePage('Could not delete',
                        '<p class="lead">Something went wrong removing the message. Please try again later.</p>', 502);
                }
                $db->prepare('UPDATE mail_quarantine SET status = ? WHERE id = ? AND status = ?')
                   ->execute(['deleted', $id, 'quarantined']);
                $this->logAction('mailsec.quarantine_release', (string) $id, 'success',
                    ['via' => 'self-service', 'action' => 'delete', 'recipient' => $rcpt]);
                return $this->quarantinePage('Deleted', '<p class="lead">The message has been permanently deleted.</p>', 200);
            }

            // release (+ optionally allow the sender)
            $res = $this->agent->execute('mailsec.releaseQuarantine', [
                'spool_path' => $row['spool_path'],
                'recipient'  => $rcpt,
                'sender'     => $row['sender'],
            ], $actor);
            if (!($res['success'] ?? false)) {
                return $this->quarantinePage('Could not release',
                    '<p class="lead">Something went wrong delivering the message. Please try again later.</p>', 502);
            }
            $db->prepare('UPDATE mail_quarantine SET status = ?, released_at = NOW(), released_by = ? WHERE id = ? AND status = ?')
               ->execute(['released', $actor, $id, 'quarantined']);

            $allowed = false;
            if ($action === 'allow') {
                $sender = strtolower(trim((string) ($row['sender'] ?? '')));
                if ($sender !== '' && filter_var($sender, FILTER_VALIDATE_EMAIL)) {
                    try {
                        $db->prepare(
                            "INSERT INTO mail_security_global_whitelist (type, value, description, created_by)
                             VALUES ('email', ?, ?, ?)
                             ON DUPLICATE KEY UPDATE description = VALUES(description)"
                        )->execute([$sender, 'Allowed via quarantine digest', $actor]);
                        $this->syncMapsBestEffort();
                        $allowed = true;
                    } catch (\Throwable $e) {
                        // Non-fatal: the message is already released regardless.
                    }
                }
            }

            $this->logAction('mailsec.quarantine_release', (string) $id, 'success',
                ['via' => 'self-service', 'action' => $action, 'recipient' => $rcpt]);
            return $this->quarantinePage(
                $allowed ? 'Released &amp; sender allowed' : 'Released',
                $allowed
                    ? '<p class="lead">The message has been delivered to your inbox, and future mail from this sender will no longer be held.</p>'
                    : '<p class="lead">The message has been delivered to your inbox.</p>',
                200
            );
        } catch (\Throwable $e) {
            return $this->quarantinePage('Error', '<p class="lead">Something went wrong. Please try again later.</p>', 500);
        }
    }

    /** Stable HMAC secret for self-service links; generated once, stored in settings. */
    private function quarantineLinkSecret(): string
    {
        $secret = (string) ($this->getSetting('quarantine_link_secret') ?? '');
        if ($secret === '') {
            $secret = bin2hex(random_bytes(32));
            $this->putSetting('quarantine_link_secret', $secret);
        }
        return $secret;
    }

    /** base64url(HMAC-SHA256(id|exp|recipient)). MUST match quarantine-maintenance.php. */
    private function quarantineSign(int $id, int $exp, string $recipient): string
    {
        $raw = hash_hmac('sha256', $id . '.' . $exp . '.' . strtolower(trim($recipient)), $this->quarantineLinkSecret(), true);
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Verify a self-service token and return the quarantine row, or null if the
     * token is malformed, expired, tampered with, or the message is gone.
     */
    private function quarantineVerifyToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$idPart, $expPart, $sig] = $parts;
        if (!ctype_digit($idPart) || !ctype_digit($expPart)) {
            return null;
        }
        $id  = (int) $idPart;
        $exp = (int) $expPart;
        if ($id <= 0 || $exp < time()) {
            return null;
        }
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare(
                'SELECT id, message_id, sender, recipient, subject, spam_score, spool_path, status, created_at
                 FROM mail_quarantine WHERE id = ? LIMIT 1'
            );
            $stmt->execute([$id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return null;
        }
        if (!$row) {
            return null;
        }
        $expected = $this->quarantineSign($id, $exp, (string) ($row['recipient'] ?? ''));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        return $row;
    }

    /** Minimal self-contained HTML page for the self-service quarantine flow. */
    private function quarantinePage(string $title, string $bodyHtml, int $status): Response
    {
        $t = htmlspecialchars(str_replace('&amp;', '&', $title), ENT_QUOTES, 'UTF-8');
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>' . $t . ' - Mail Security</title><style>'
            . 'body{font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:40px 16px;}'
            . '.card{max-width:560px;margin:0 auto;background:#1e293b;border:1px solid #334155;border-radius:14px;padding:28px 30px;box-shadow:0 10px 40px rgba(0,0,0,.35);}'
            . 'h1{font-size:20px;margin:0 0 16px;}'
            . '.lead{color:#cbd5e1;margin:0 0 18px;line-height:1.5;}'
            . 'table.meta{width:100%;border-collapse:collapse;margin:0 0 22px;font-size:14px;}'
            . 'table.meta th{text-align:left;color:#94a3b8;font-weight:500;padding:6px 12px 6px 0;vertical-align:top;white-space:nowrap;width:90px;}'
            . 'table.meta td{padding:6px 0;word-break:break-word;}'
            . '.btn{display:block;width:100%;box-sizing:border-box;margin:10px 0;padding:12px 16px;border:0;border-radius:10px;font-size:15px;cursor:pointer;color:#fff;}'
            . '.release{background:#16a34a;}.allow{background:#2563eb;}.delete{background:#475569;}'
            . '.brand{color:#64748b;font-size:12px;text-align:center;margin-top:18px;}'
            . '</style></head><body><div class="card"><h1>' . $t . '</h1>' . $bodyHtml
            . '<div class="brand">DEVCON Mail Security</div></div></body></html>';
        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * Drain the Rspamd scan history into mail_security_events on demand. The cron
     * does this every minute; this lets an admin force a refresh (e.g. right
     * after sending a test message).
     */
    public function syncEvents(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }

        $params = [];
        if ($request->input('reset')) {
            $params['reset'] = true;
        }

        $result = $this->agent->execute('mailsec.syncEvents', $params, $this->getActor(), 90);

        if (!($result['success'] ?? false)) {
            $this->logAction('mailsec.event_sync', 'global', 'failed', ['error' => $result['error'] ?? '']);
            return Response::error($result['error'] ?? 'Event sync failed');
        }

        $this->logAction('mailsec.event_sync', 'global', 'success', $result['data'] ?? []);
        return Response::success($result['data'] ?? [], $result['message'] ?? 'Event sync complete');
    }

    // ==================== REACTIVE LEARNING LOOP ====================

    /**
     * Combined learning-loop dashboard: live IMAPSieve wiring + Bayes counts
     * from the agent, plus aggregated learning activity from the panel DB.
     * Surfaces what users are training the engine on, regardless of whether
     * they used webmail or a native IMAP client.
     */
    public function learning(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }

        $days = max(1, min(365, (int) $request->getQuery('days', 30)));

        $live = $this->agent->execute('mailsec.learnStatus', [], $this->getActor(), 15);
        $loop = ($live['success'] ?? false) ? ($live['data'] ?? []) : [];

        $totals = ['spam' => 0, 'ham' => 0];
        $sourceBreakdown = [];
        $daily = [];
        $recent = [];
        $topUsers = [];

        try {
            $db = $this->container->getDatabase();

            // Direction totals over the window.
            $stmt = $db->prepare(
                "SELECT direction, COUNT(*) AS c
                 FROM mail_security_learn_events
                 WHERE ts >= (NOW() - INTERVAL ? DAY)
                 GROUP BY direction"
            );
            $stmt->execute([$days]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $totals[$row['direction']] = (int) $row['c'];
            }

            // Source x direction breakdown: tells admin whether feedback is
            // coming from webmail buttons, IMAP drags, or admin actions.
            $stmt = $db->prepare(
                "SELECT source, direction, COUNT(*) AS c
                 FROM mail_security_learn_events
                 WHERE ts >= (NOW() - INTERVAL ? DAY)
                 GROUP BY source, direction"
            );
            $stmt->execute([$days]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $src = (string) $row['source'];
                $dir = (string) $row['direction'];
                if (!isset($sourceBreakdown[$src])) {
                    $sourceBreakdown[$src] = ['spam' => 0, 'ham' => 0];
                }
                $sourceBreakdown[$src][$dir] = (int) $row['c'];
            }

            // Daily trend for the chart in the UI.
            $stmt = $db->prepare(
                "SELECT DATE(ts) AS d,
                        SUM(direction = 'spam') AS spam,
                        SUM(direction = 'ham')  AS ham
                 FROM mail_security_learn_events
                 WHERE ts >= (NOW() - INTERVAL ? DAY)
                 GROUP BY DATE(ts)
                 ORDER BY DATE(ts)"
            );
            $stmt->execute([$days]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $daily[] = [
                    'day' => $row['d'],
                    'spam' => (int) $row['spam'],
                    'ham' => (int) $row['ham'],
                ];
            }

            // Recent feed: who reported what, when. Capped so the response
            // stays small even on busy hosts.
            $stmt = $db->prepare(
                "SELECT ts, direction, source, user_email, sender, message_id, rspamc_rc, opted_out
                 FROM mail_security_learn_events
                 WHERE ts >= (NOW() - INTERVAL ? DAY)
                 ORDER BY ts DESC
                 LIMIT 50"
            );
            $stmt->execute([$days]);
            $recent = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($recent as &$r) {
                $r['rspamc_rc'] = $r['rspamc_rc'] === null ? null : (int) $r['rspamc_rc'];
                $r['opted_out'] = (bool) $r['opted_out'];
            }
            unset($r);

            // Top users by report volume.
            $stmt = $db->prepare(
                "SELECT user_email,
                        SUM(direction = 'spam') AS spam,
                        SUM(direction = 'ham')  AS ham
                 FROM mail_security_learn_events
                 WHERE ts >= (NOW() - INTERVAL ? DAY) AND user_email IS NOT NULL AND user_email <> ''
                 GROUP BY user_email
                 ORDER BY (SUM(direction = 'spam') + SUM(direction = 'ham')) DESC
                 LIMIT 10"
            );
            $stmt->execute([$days]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $topUsers[] = [
                    'user_email' => (string) $row['user_email'],
                    'spam' => (int) $row['spam'],
                    'ham' => (int) $row['ham'],
                ];
            }
        } catch (\Throwable $e) {
            // Schema may be brand-new; empty defaults are fine.
        }

        // Webmail path activity (the existing MailFlow store), for parity.
        $webmail = ['spam' => 0, 'ham' => 0];
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare(
                "SELECT action, COUNT(*) AS c
                 FROM webmail_spam_stats
                 WHERE created_at >= (NOW() - INTERVAL ? DAY)
                   AND action IN ('reported_spam', 'not_spam')
                 GROUP BY action"
            );
            $stmt->execute([$days]);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if ($row['action'] === 'reported_spam') {
                    $webmail['spam'] = (int) $row['c'];
                } elseif ($row['action'] === 'not_spam') {
                    $webmail['ham'] = (int) $row['c'];
                }
            }
        } catch (\Throwable $e) {
            // webmail_spam_stats lives in the MailFlow app; absent on fresh installs.
        }

        // Aggregate snapshot of MailFlow's per-user spam settings so the panel
        // can show "N users opted out", "M users use INBOX.Junk", etc. without
        // dumping individual rows.
        $spamPrefs = $this->aggregateWebmailSpamPrefs();

        return Response::success([
            'days' => $days,
            'loop' => $loop,
            'loop_setting' => $this->getSetting('learn_loop_enabled') !== '0',
            'totals' => $totals,
            'source_breakdown' => $sourceBreakdown,
            'daily' => $daily,
            'recent' => $recent,
            'top_users' => $topUsers,
            'webmail' => $webmail,
            'spam_prefs' => $spamPrefs,
        ]);
    }

    /**
     * Aggregate stats over MailFlow's webmail_spam_settings so the admin can
     * see at a glance how many users opted out of training, what spam folder
     * names are in play, etc. Read-only; absent table -> empty payload.
     */
    private function aggregateWebmailSpamPrefs(): array
    {
        $out = [
            'available' => false,
            'users_total' => 0,
            'users_opted_out' => 0,
            'folders' => [],
        ];
        try {
            $db = $this->container->getDatabase();
            $db->query('SELECT 1 FROM webmail_spam_settings LIMIT 0');
            $out['available'] = true;

            $out['users_total'] = (int) $db->query(
                'SELECT COUNT(*) FROM webmail_spam_settings'
            )->fetchColumn();
            $out['users_opted_out'] = (int) $db->query(
                'SELECT COUNT(*) FROM webmail_spam_settings WHERE auto_training_enabled = 0'
            )->fetchColumn();

            $stmt = $db->query(
                "SELECT COALESCE(NULLIF(spam_folder, ''), 'INBOX.Spam') AS folder, COUNT(*) AS c
                 FROM webmail_spam_settings
                 GROUP BY folder
                 ORDER BY c DESC
                 LIMIT 10"
            );
            if ($stmt) {
                foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    $out['folders'][] = [
                        'folder' => (string) $row['folder'],
                        'users' => (int) $row['c'],
                    ];
                }
            }
        } catch (\Throwable $e) {
            // table missing on installs without MailFlow; leave defaults.
        }
        return $out;
    }

    /**
     * Toggle Rspamd's automatic Bayes self-training. Independent of the
     * IMAPSieve user-feedback loop: autolearn=true means Rspamd trains its
     * corpus from every message scoring deep in spam or ham territory;
     * autolearn=false means the corpus is fed only by user feedback.
     *
     * Body: { enabled: bool }
     */
    public function setBayesAutolearn(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN);

        $result = $this->agent->execute('mailsec.setBayesAutolearn', [
            'enabled' => $enabled,
        ], $this->getActor(), 60);

        if (!($result['success'] ?? false)) {
            $this->logAction('mailsec.bayes_autolearn', 'engine', 'failed', ['error' => $result['error'] ?? '', 'enabled' => $enabled]);
            return Response::error($result['error'] ?? 'Failed to update Bayes autolearn');
        }

        $this->putSetting('bayes_autolearn', $enabled ? '1' : '0');
        $this->logAction('mailsec.bayes_autolearn', 'engine', 'success', ['enabled' => $enabled]);

        return Response::success($result['data'] ?? [], $result['message'] ?? 'Bayes autolearn updated');
    }

    /**
     * Install or remove the IMAPSieve learning hooks. Persists the toggle into
     * mail_security_settings so the panel UI matches the live state after a
     * page refresh, and lets the install action keep things in sync.
     *
     * Body: { enabled: bool }
     */
    public function setLearning(Request $request): Response
    {
        if ($denied = $this->requireAdmin()) {
            return $denied;
        }
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOLEAN);

        $result = $this->agent->execute('mailsec.setupLearning', [
            'enabled' => $enabled,
        ], $this->getActor(), 60);

        if (!($result['success'] ?? false)) {
            $this->logAction('mailsec.learn_setup', 'engine', 'failed', ['error' => $result['error'] ?? '', 'enabled' => $enabled]);
            return Response::error($result['error'] ?? 'Failed to update learning loop');
        }

        $this->putSetting('learn_loop_enabled', $enabled ? '1' : '0');
        $this->logAction('mailsec.learn_setup', 'engine', 'success', ['enabled' => $enabled]);

        return Response::success($result['data'] ?? [], $enabled
            ? 'Learning loop enabled'
            : 'Learning loop disabled');
    }

    // ==================== HELPERS ====================

    private function putSetting(string $key, string $value): void
    {
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare(
                'INSERT INTO mail_security_settings (k, v, updated_by) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)'
            );
            $stmt->execute([$key, $value, $this->getActor()]);
        } catch (\Throwable $e) {
            // Non-fatal: settings mirror is best-effort.
        }
    }

    private function getSetting(string $key): ?string
    {
        try {
            $db = $this->container->getDatabase();
            $stmt = $db->prepare('SELECT v FROM mail_security_settings WHERE k = ? LIMIT 1');
            $stmt->execute([$key]);
            $v = $stmt->fetchColumn();
            return $v === false ? null : (string)$v;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
