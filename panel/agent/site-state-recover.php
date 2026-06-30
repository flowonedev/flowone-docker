<?php
/**
 * vpsadmin-site-state-recover
 *
 * One-shot CLI that recovers sites stranded in an in-flight
 * actual_state (`provisioning` / `deleting` / `restoring`) with no
 * live `site_jobs` row to drive them forward.
 *
 * The "0 in flight, but the site is still on deleting" symptom is
 * what this script exists to fix. It is the SITE-side analogue of
 * `dead-lease-sweep.php` (which handles the JOB side).
 *
 * Usage
 * -----
 *   site-state-recover.php                       sweep with default grace (300s)
 *   site-state-recover.php --grace=N             override grace seconds
 *   site-state-recover.php --limit=N             cap rows touched this tick
 *   site-state-recover.php --domain=test3        target a single site (no grace check)
 *   site-state-recover.php --to=degraded         force landing for --domain mode
 *   site-state-recover.php --list                list candidates, do NOT recover
 *   site-state-recover.php --dry-run             plan recoveries without writing
 *   site-state-recover.php --json                machine-readable summary
 *   site-state-recover.php --verbose             per-row trace
 *   site-state-recover.php --help                show this and exit
 *
 * Modes
 * -----
 *   sweep   (default): scan every wedged site whose updated_at is
 *           older than --grace seconds and transition each to its
 *           canonical "stuck" landing state (degraded / failed). The
 *           grace period prevents racing a saga that's about to
 *           commit its terminal transition.
 *
 *   list:   read-only inspection. Same scan, no writes.
 *
 *   single (--domain=X): target one specific site. The grace check
 *           is skipped (the operator has already concluded the site
 *           is stuck). Pass --to=STATE to override the landing.
 *
 * Exit codes
 * ----------
 *   0  - sweep completed (recovered count may be zero)
 *   1  - bootstrap failed
 *   2  - --domain target not found OR refused by the state machine
 *
 * Cron / systemd timer entry (every 5 minutes is plenty)
 * ------------------------------------------------------
 *   * /5 * * * *  root  /usr/local/lsws/lsphp83/bin/php \
 *     /var/www/vps-admin/agent/site-state-recover.php --json \
 *     >> /var/log/flowone/site-state-recover.log 2>&1
 *
 * Safety
 * ------
 * The sweeper deliberately lands every wedged site in a FAILED-style
 * state (degraded / failed), never optimistically in a SUCCESS state.
 * That means a half-deleted site will not be silently promoted to
 * `absent` by this script - the operator must confirm the cleanup.
 *
 * Run command on server (interactive recovery for one stuck site):
 *   /usr/local/lsws/lsphp83/bin/php /var/www/vps-admin/agent/site-state-recover.php \
 *     --domain=test3 --verbose
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "site-state-recover must run from CLI\n");
    exit(1);
}

spl_autoload_register(function (string $class): void {
    $prefix = 'VpsAdmin\\Agent\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use VpsAdmin\Agent\Provisioner\DTOs\ActorContext;
use VpsAdmin\Agent\Provisioner\Exceptions\InvalidStateTransition;
use VpsAdmin\Agent\Provisioner\Exceptions\StateGuardFailed;
use VpsAdmin\Agent\Provisioner\Reconciler\StuckSiteSweeper;
use VpsAdmin\Agent\Provisioner\Services\AuditLogger;
use VpsAdmin\Agent\Provisioner\Services\SecretMasker;
use VpsAdmin\Agent\Provisioner\SiteStateMachine;
use VpsAdmin\Agent\Provisioner\Support\PanelDatabase;

$opts = getopt('', [
    'grace:', 'limit:',
    'domain:', 'to:',
    'list', 'dry-run', 'json', 'verbose',
    'help',
]);

if (isset($opts['help'])) {
    fwrite(STDOUT, file_get_contents(__FILE__, false, null, 0, 2800));
    exit(0);
}

$grace = isset($opts['grace']) ? max(0, (int) $opts['grace']) : StuckSiteSweeper::DEFAULT_GRACE_SECONDS;
$limit = isset($opts['limit']) ? max(1, (int) $opts['limit']) : null;
$domain = isset($opts['domain']) ? (string) $opts['domain'] : null;
$forceTo = isset($opts['to']) ? (string) $opts['to'] : null;
$listMode = isset($opts['list']);
$dryRun = isset($opts['dry-run']);
$json = isset($opts['json']);
$verbose = isset($opts['verbose']);

// ─── Bootstrap ───────────────────────────────────────────────
try {
    $db = PanelDatabase::fromDefaultConfigFiles();
    $db->pdo()->query('SELECT 1');

    $masker = new SecretMasker();
    $audit = new AuditLogger($db, $masker);
    $stateMachine = new SiteStateMachine($db, $audit);
    $sweeper = new StuckSiteSweeper(
        database: $db,
        stateMachine: $stateMachine,
        audit: $audit,
        graceSeconds: $grace,
    );
} catch (\Throwable $e) {
    fwrite(STDERR, '[recover] bootstrap failed: '
        . $e::class . ': ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

// ─── Single-domain recovery ──────────────────────────────────
if ($domain !== null && $domain !== '') {
    exit(runSingleDomain($db, $stateMachine, $domain, $forceTo, $dryRun, $json, $verbose));
}

// ─── List candidates only ────────────────────────────────────
if ($listMode) {
    $rows = $sweeper->listCandidates($limit);
    if ($json) {
        echo json_encode([
            'mode' => 'list',
            'grace_seconds' => $grace,
            'count' => count($rows),
            'candidates' => $rows,
        ], JSON_PRETTY_PRINT) . "\n";
        exit(0);
    }
    if ($rows === []) {
        echo "[recover] no stuck sites (grace={$grace}s)\n";
        exit(0);
    }
    echo sprintf(
        "[recover] %d stuck site(s) older than %ds with no live job:\n",
        count($rows), $grace
    );
    foreach ($rows as $r) {
        echo sprintf(
            "  - id=%-6d %-40s state=%-13s updated_at=%s\n",
            (int) $r['id'],
            (string) $r['domain'],
            (string) $r['actual_state'],
            (string) ($r['updated_at'] ?? ''),
        );
    }
    exit(0);
}

// ─── Sweep mode (default) ────────────────────────────────────
try {
    $result = $sweeper->sweep($limit, $dryRun);
} catch (\Throwable $e) {
    fwrite(STDERR, '[recover] sweep failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

if ($json) {
    echo json_encode([
        'mode' => $dryRun ? 'sweep-dry-run' : 'sweep',
        'grace_seconds' => $grace,
        'result' => $result->toArray(),
    ], JSON_PRETTY_PRINT) . "\n";
    exit(0);
}

echo sprintf(
    "[recover] scanned=%d recovered=%d skipped=%d elapsed=%dms dry_run=%s grace=%ds\n",
    $result->scanned, $result->recovered, $result->skipped, $result->elapsedMs,
    $result->dryRun ? 'yes' : 'no',
    $grace,
);
if (($verbose || $dryRun) && $result->recoveries !== []) {
    echo "  Recoveries:\n";
    foreach ($result->recoveries as $rec) {
        echo sprintf(
            "    - id=%-6d %-40s %s -> %s%s\n",
            $rec['site_id'], $rec['domain'],
            $rec['from'], $rec['to'],
            $rec['dry_run'] ? '  (dry-run)' : '',
        );
    }
}
exit(0);


// ─────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────

/**
 * Single-domain recovery path. Looks up the row, refuses if it's not
 * actually in an in-flight state, and transitions it to either the
 * caller-supplied --to=STATE or the canonical landing for that
 * in-flight state.
 */
function runSingleDomain(
    PanelDatabase $db,
    SiteStateMachine $stateMachine,
    string $domain,
    ?string $forceTo,
    bool $dryRun,
    bool $json,
    bool $verbose
): int {
    $stmt = $db->pdo()->prepare(
        'SELECT id, domain, actual_state, desired_state, updated_at
           FROM sites WHERE domain = :domain LIMIT 1'
    );
    $stmt->execute(['domain' => $domain]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!$row) {
        $msg = "site '{$domain}' not found";
        if ($json) {
            echo json_encode(['ok' => false, 'error' => $msg]) . "\n";
        } else {
            fwrite(STDERR, "[recover] {$msg}\n");
        }
        return 2;
    }

    $from = (string) $row['actual_state'];
    $landing = $forceTo ?? canonicalLandingFor($from);
    if ($landing === null) {
        $msg = "site '{$domain}' is in state '{$from}' which has no canonical "
            . "stuck-recovery landing. Pass --to=STATE explicitly if you "
            . "really want to force a transition.";
        if ($json) {
            echo json_encode(['ok' => false, 'error' => $msg]) . "\n";
        } else {
            fwrite(STDERR, "[recover] {$msg}\n");
        }
        return 2;
    }

    if (!$stateMachine->canTransition($from, $landing)) {
        $msg = "state machine refuses transition '{$from}' -> '{$landing}'. "
            . "Use --to=STATE to pick a legal target. Legal: "
            . implode(',', $stateMachine->legalTransitionsFrom($from));
        if ($json) {
            echo json_encode(['ok' => false, 'error' => $msg]) . "\n";
        } else {
            fwrite(STDERR, "[recover] {$msg}\n");
        }
        return 2;
    }

    if ($dryRun) {
        $payload = [
            'ok' => true,
            'mode' => 'single-domain-dry-run',
            'domain' => $domain,
            'site_id' => (int) $row['id'],
            'from' => $from,
            'to' => $landing,
            'updated_at' => (string) $row['updated_at'],
        ];
        echo $json ? json_encode($payload) . "\n"
                   : "[recover] WOULD transition '{$domain}': {$from} -> {$landing}\n";
        return 0;
    }

    try {
        $stateMachine->transition(
            siteId: (int) $row['id'],
            from: $from,
            to: $landing,
            reason: "site-state-recover: operator-initiated unstick "
                . "(was '{$from}' for {$row['updated_at']})",
            actor: ActorContext::cli('site-state-recover'),
        );
    } catch (StateGuardFailed | InvalidStateTransition $e) {
        $msg = "transition refused: " . $e->getMessage();
        if ($json) {
            echo json_encode(['ok' => false, 'error' => $msg]) . "\n";
        } else {
            fwrite(STDERR, "[recover] {$msg}\n");
        }
        return 2;
    } catch (\Throwable $e) {
        $msg = "transition raised " . $e::class . ': ' . $e->getMessage();
        if ($json) {
            echo json_encode(['ok' => false, 'error' => $msg]) . "\n";
        } else {
            fwrite(STDERR, "[recover] {$msg}\n");
        }
        return 1;
    }

    $payload = [
        'ok' => true,
        'mode' => 'single-domain',
        'domain' => $domain,
        'site_id' => (int) $row['id'],
        'from' => $from,
        'to' => $landing,
    ];
    echo $json
        ? json_encode($payload) . "\n"
        : "[recover] '{$domain}': {$from} -> {$landing} OK\n";
    if ($verbose && !$json) {
        echo "  Audit row written; site is now in '{$landing}'. "
            . "Re-enqueue a saga via the panel if further action is needed.\n";
    }
    return 0;
}

/**
 * Map of in-flight state -> canonical "stuck" landing. Mirrors the
 * sweeper's internal map so single-domain mode behaves the same as
 * a sweep would.
 */
function canonicalLandingFor(string $inFlight): ?string
{
    return match ($inFlight) {
        'provisioning' => 'degraded',
        'deleting'     => 'degraded',
        'restoring'    => 'failed',
        default        => null,
    };
}
