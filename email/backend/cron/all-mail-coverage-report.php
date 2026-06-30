#!/usr/bin/env php
<?php
/**
 * Daily All Mail Coverage Report.
 *
 * Scans the structured PHP error log for the previous 24h and tallies how
 * many ALL_MAIL fetches degraded, which folders fell back to which tier,
 * and which accounts had invariant violations. Emails the admin only when
 * one or more accounts had degraded folders. Healthy log volume in the
 * steady state is < 10KB / day.
 *
 * Run nightly:
 *   30 2 * * * /usr/local/lsws/lsphp83/bin/php /var/www/vps-email/backend/cron/all-mail-coverage-report.php
 */

require_once __DIR__ . '/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$config = require __DIR__ . '/../src/config.php';

$errorLog = ini_get('error_log') ?: '/var/www/vps-email/backend/logs/php_errors.log';
$logFile = __DIR__ . '/../storage/logs/all-mail-coverage-report.log';

if (!is_dir(dirname($logFile))) {
    @mkdir(dirname($logFile), 0755, true);
}

$cutoff = time() - 86400;

$stages = [
    'full_range' => 0,
    'binary_split' => 0,
    'chunk_50' => 0,
    'per_uid' => 0,
];
$states = [
    'healthy' => 0,
    'degraded' => 0,
    'quarantined' => 0,
];
$invariantViolations = 0;
$accountDegrades = []; // account_id -> count
$folderDegrades = [];  // folder_path -> count
$truncations = 0;

if (!is_readable($errorLog)) {
    fwrite(STDERR, "error log not readable: {$errorLog}\n");
    exit(1);
}

$fp = @fopen($errorLog, 'r');
if (!$fp) {
    fwrite(STDERR, "cannot open error log\n");
    exit(1);
}
while (!feof($fp)) {
    $line = fgets($fp);
    if ($line === false) {
        break;
    }
    if (!str_contains($line, '[ALLMAIL]')) {
        continue;
    }

    if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
        $ts = strtotime($m[1]);
        if ($ts !== false && $ts < $cutoff) {
            continue;
        }
    }

    if (preg_match('/\[ALLMAIL\]\s+(\{.*\})$/', trim($line), $jm)) {
        $payload = json_decode($jm[1], true);
        if (!is_array($payload)) {
            continue;
        }
        $evt = (string) ($payload['evt'] ?? '');
        switch ($evt) {
            case 'allmail_fallback':
                $stage = $payload['fallback_stage'] ?? 'full_range';
                if (isset($stages[$stage])) {
                    $stages[$stage]++;
                }
                break;
            case 'allmail_skip':
                if (!empty($payload['folder_path'])) {
                    $folderDegrades[$payload['folder_path']] = ($folderDegrades[$payload['folder_path']] ?? 0) + 1;
                }
                break;
            case 'state_transition':
                $to = $payload['to_state'] ?? 'healthy';
                if (isset($states[$to])) {
                    $states[$to]++;
                }
                if (!empty($payload['account_id']) && in_array($to, ['degraded', 'quarantined'], true)) {
                    $accountDegrades[$payload['account_id']] = ($accountDegrades[$payload['account_id']] ?? 0) + 1;
                }
                break;
            case 'allmail_invariant_violation':
                $invariantViolations++;
                if (!empty($payload['folder_path'])) {
                    $folderDegrades[$payload['folder_path']] = ($folderDegrades[$payload['folder_path']] ?? 0) + 1;
                }
                break;
            case 'truncation':
                $truncations++;
                break;
        }
    }
}
fclose($fp);

$summary = [
    'window' => '24h ending ' . gmdate('c'),
    'fallback_stages' => $stages,
    'states' => $states,
    'invariant_violations' => $invariantViolations,
    'truncation_events' => $truncations,
    'accounts_with_degrades' => count($accountDegrades),
    'folders_with_degrades' => count($folderDegrades),
    'top_degraded_folders' => array_slice(
        $folderDegrades,
        0,
        20,
        true
    ),
];

$line = date('[Y-m-d H:i:s] ') . 'all-mail-coverage-report ' . json_encode($summary) . "\n";
@file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
echo $line;

// Email admin only when there are degrades. Steady-state log volume is the
// summary line above (< 10 KB / day on healthy systems).
$adminEmail = $config['admin_email'] ?? null;
$shouldAlert = ($invariantViolations > 0) || (count($accountDegrades) > 0);
if ($adminEmail && $shouldAlert) {
    $body = "ALL_MAIL coverage report (last 24h):\n\n"
        . json_encode($summary, JSON_PRETTY_PRINT) . "\n\n"
        . "See: {$logFile}\n";
    @mail(
        $adminEmail,
        sprintf('[FlowOne] ALL_MAIL coverage: %d account(s) degraded', count($accountDegrades)),
        $body
    );
}

exit(0);
