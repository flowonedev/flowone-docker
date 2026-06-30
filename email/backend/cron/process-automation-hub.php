<?php

/**
 * Automation Hub Cron Processor
 * 
 * Run every minute: * * * * * php /path/to/process-automation-hub.php
 * 
 * Responsibilities:
 * 1. Evaluate schedule-based triggers
 * 2. Evaluate server health triggers
 * 3. Resume delayed executions (Delay nodes)
 * 4. Clean up stale executions
 */

require_once __DIR__ . '/bootstrap.php';

$configFile = __DIR__ . '/../src/config.php';
if (!file_exists($configFile)) {
    error_log("AutomationHub cron: config.php not found");
    exit(1);
}
$config = require $configFile;

// Prevent overlapping cron runs via file lock
$lockFile = sys_get_temp_dir() . '/automation_hub_cron.lock';
$lockFp = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    error_log("AutomationHub cron: another instance is already running");
    exit(0);
}

try {
    $db = \Webmail\Core\Database::getConnection($config);
} catch (\Throwable $e) {
    error_log("AutomationHub cron: DB connection failed: " . $e->getMessage());
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(1);
}

$engine = new \Webmail\Addons\AutomationHub\Services\WorkflowEngineService($config);
$monitor = new \Webmail\Addons\AutomationHub\Services\ServerMonitorBridge($config);

// ── 1. Schedule-based triggers ──────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT n.workflow_id, n.node_uid, n.config, w.user_email
        FROM automation_hub_nodes n
        JOIN automation_hub_workflows w ON n.workflow_id = w.id
        WHERE n.node_type = 'trigger.schedule.cron'
          AND w.is_active = 1
    ");
    $stmt->execute();

    foreach ($stmt->fetchAll() as $row) {
        $nodeConfig = json_decode($row['config'], true) ?? [];
        $scheduleType = $nodeConfig['schedule_type'] ?? 'interval';

        $shouldFire = false;

        if ($scheduleType === 'interval') {
            $value = (int)($nodeConfig['interval_value'] ?? 5);
            $unit = $nodeConfig['interval_unit'] ?? 'minutes';
            $intervalMinutes = match ($unit) {
                'minutes' => $value,
                'hours' => $value * 60,
                'days' => $value * 1440,
                default => $value,
            };

            // Check last execution time
            $stmtLast = $db->prepare("
                SELECT MAX(started_at) as last_run
                FROM automation_hub_executions
                WHERE workflow_id = ? AND status IN ('completed', 'running')
            ");
            $stmtLast->execute([$row['workflow_id']]);
            $lastRun = $stmtLast->fetch()['last_run'] ?? null;

            if (!$lastRun || (time() - strtotime($lastRun)) >= ($intervalMinutes * 60)) {
                $shouldFire = true;
            }
        } elseif ($scheduleType === 'cron') {
            $expression = $nodeConfig['cron_expression'] ?? '*/5 * * * *';
            $shouldFire = cronMatchesNow($expression);
        } elseif ($scheduleType === 'daily') {
            $time = $nodeConfig['daily_time'] ?? '08:00';
            $now = date('H:i');
            $shouldFire = ($now === $time);
        }

        if ($shouldFire) {
            try {
                $engine->execute((int)$row['workflow_id'], [
                    'trigger' => 'schedule',
                    'scheduled_at' => date('Y-m-d H:i:s'),
                    'user_email' => $row['user_email'],
                ]);
            } catch (\Throwable $e) {
                error_log("AutomationHub cron schedule error [wf:{$row['workflow_id']}]: " . $e->getMessage());
            }
        }
    }
} catch (\Throwable $e) {
    error_log("AutomationHub cron schedule phase error: " . $e->getMessage());
}

// ── 2. Server health triggers ───────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT n.workflow_id, n.node_uid, n.config, w.user_email
        FROM automation_hub_nodes n
        JOIN automation_hub_workflows w ON n.workflow_id = w.id
        WHERE n.node_type = 'trigger.server.health'
          AND w.is_active = 1
    ");
    $stmt->execute();

    foreach ($stmt->fetchAll() as $row) {
        $nodeConfig = json_decode($row['config'], true) ?? [];
        $metric = $nodeConfig['metric'] ?? 'cpu_load';
        $condition = $nodeConfig['condition'] ?? ($nodeConfig['status_condition'] ?? 'above');
        $threshold = (float)($nodeConfig['threshold'] ?? 90);
        $service = $nodeConfig['service'] ?? null;

        // Debounce: only fire once per 5 minutes per workflow
        $stmtDebounce = $db->prepare("
            SELECT COUNT(*) FROM automation_hub_executions
            WHERE workflow_id = ? AND started_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmtDebounce->execute([$row['workflow_id']]);
        if ((int)$stmtDebounce->fetchColumn() > 0) continue;

        try {
            $check = $monitor->checkMetric($metric, $condition, $threshold, $service);
            if ($check['triggered'] ?? false) {
                $engine->execute((int)$row['workflow_id'], array_merge($check, [
                    'trigger' => 'server_health',
                    'user_email' => $row['user_email'],
                ]));
            }
        } catch (\Throwable $e) {
            error_log("AutomationHub cron health error [wf:{$row['workflow_id']}]: " . $e->getMessage());
        }
    }
} catch (\Throwable $e) {
    error_log("AutomationHub cron health phase error: " . $e->getMessage());
}

// ── 3. Resume delayed executions ────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT d.*, e.workflow_id
        FROM automation_hub_delayed_executions d
        JOIN automation_hub_executions e ON d.execution_id = e.id
        WHERE d.is_processed = 0 AND d.resume_at <= NOW()
        LIMIT 50
    ");
    $stmt->execute();

    foreach ($stmt->fetchAll() as $delayed) {
        try {
            $db->prepare("UPDATE automation_hub_delayed_executions SET is_processed = 1 WHERE id = ?")->execute([$delayed['id']]);

            $inputData = json_decode($delayed['input_data'], true) ?? [];
            $engine->resumeFromNode(
                (int)$delayed['execution_id'],
                (int)$delayed['workflow_id'],
                $delayed['resume_node_uid'],
                $inputData
            );
        } catch (\Throwable $e) {
            error_log("AutomationHub cron delay resume error: " . $e->getMessage());
        }
    }
} catch (\Throwable $e) {
    error_log("AutomationHub cron delay phase error: " . $e->getMessage());
}

// ── 4. Clean up stale executions (running > 1 hour) ─────────────────
try {
    $db->prepare("
        UPDATE automation_hub_executions
        SET status = 'failed', completed_at = NOW(), error_message = 'Execution timed out'
        WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ")->execute();
} catch (\Throwable $e) {
    error_log("AutomationHub cron cleanup error: " . $e->getMessage());
}

// Release lock
flock($lockFp, LOCK_UN);
fclose($lockFp);

/**
 * Simple cron expression matcher (minute hour day month weekday).
 * Supports: asterisk, asterisk-slash-N (step), N, N-N (range).
 */
function cronMatchesNow(string $expression): bool
{
    $parts = preg_split('/\s+/', trim($expression));
    if (count($parts) !== 5) return false;

    $now = [
        (int)date('i'), // minute
        (int)date('G'), // hour
        (int)date('j'), // day of month
        (int)date('n'), // month
        (int)date('w'), // day of week (0=Sun)
    ];

    for ($i = 0; $i < 5; $i++) {
        if (!cronFieldMatches($parts[$i], $now[$i])) {
            return false;
        }
    }
    return true;
}

function cronFieldMatches(string $field, int $value): bool
{
    if ($field === '*') return true;

    if (str_starts_with($field, '*/')) {
        $step = (int)substr($field, 2);
        return $step > 0 && ($value % $step) === 0;
    }

    if (str_contains($field, '-')) {
        [$min, $max] = array_map('intval', explode('-', $field, 2));
        return $value >= $min && $value <= $max;
    }

    if (str_contains($field, ',')) {
        $values = array_map('intval', explode(',', $field));
        return in_array($value, $values);
    }

    return (int)$field === $value;
}
