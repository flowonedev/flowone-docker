<?php
/**
 * Backup Schedule Manager
 *
 * Pure, testable helpers for the backup scheduling system:
 *  - cron expression building (with weekly day-of-week support)
 *  - cron line parsing (including disabled/commented lines)
 *  - next-run computation
 *  - run-state file (last run outcome per schedule, written by backup-runner.php)
 *
 * Kept separate from BackupAction so the logic can be unit-tested without an
 * agent runtime and so the (already oversized) action class does not grow.
 */

namespace VpsAdmin\Agent\Lib;

class BackupScheduleManager
{
    public const DEFAULT_STATE_FILE = '/var/www/vps-admin/backups/.runner-state.json';
    public const RUNNER_MARKER = 'backup-runner.php';

    /** Max schedules tracked in the state file (oldest dropped beyond this). */
    private const STATE_MAX_ENTRIES = 50;

    public const DAY_LABELS = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    /**
     * Build a cron expression for a schedule frequency.
     * Weekly schedules honor $dayOfWeek (0=Sunday .. 6=Saturday).
     */
    public static function buildCronExpr(string $frequency, int $hour, int $minute, int $dayOfWeek = 0): string
    {
        $dayOfWeek = max(0, min(6, $dayOfWeek));

        return match ($frequency) {
            'hourly' => "{$minute} * * * *",
            'daily' => "{$minute} {$hour} * * *",
            'weekly' => "{$minute} {$hour} * * {$dayOfWeek}",
            'monthly' => "{$minute} {$hour} 1 * *",
            default => "{$minute} {$hour} * * *",
        };
    }

    /**
     * Parse a single cron file line into schedule fields.
     *
     * Handles disabled lines ("# 0 3 * * * root ..."): they are returned with
     * 'enabled' => false instead of being dropped, so toggled-off schedules
     * stay visible in the panel.
     *
     * Returns null for blank lines, non-cron comments, and lines that do not
     * invoke backup-runner.php.
     */
    public static function parseCronLine(string $line): ?array
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return null;
        }

        $enabled = true;
        if ($trimmed[0] === '#') {
            $enabled = false;
            $trimmed = ltrim($trimmed, "# \t");
            if ($trimmed === '') {
                return null;
            }
        }

        if (!preg_match('/^(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(.+)$/', $trimmed, $m)) {
            return null;
        }

        // Only backup-runner entries are schedules; anything else in the file
        // (headers, unrelated jobs, plain comments) is ignored.
        if (strpos($m[7], self::RUNNER_MARKER) === false) {
            return null;
        }

        $schedule = [
            // md5 of the enabled (uncommented) form so the id is stable across
            // enable/disable toggles. updateSchedule already matches both forms.
            'id' => md5($trimmed),
            'minute' => $m[1],
            'hour' => $m[2],
            'day' => $m[3],
            'month' => $m[4],
            'weekday' => $m[5],
            'user' => $m[6],
            'command' => $m[7],
            'raw' => $trimmed,
            'enabled' => $enabled,
        ];

        $schedule['frequency'] = self::frequencyFor($schedule);
        if ($schedule['frequency'] === 'weekly' && is_numeric($schedule['weekday'])) {
            $dow = ((int)$schedule['weekday']) % 7; // cron allows 7 = Sunday
            $schedule['day_of_week'] = $dow;
            $schedule['day_of_week_label'] = self::DAY_LABELS[$dow] ?? (string)$dow;
        }

        return $schedule;
    }

    /**
     * Derive the panel frequency name from cron fields.
     */
    public static function frequencyFor(array $fields): string
    {
        $weekday = $fields['weekday'] ?? '*';
        $day = $fields['day'] ?? '*';
        $hour = $fields['hour'] ?? '*';

        if ($weekday !== '*') {
            return 'weekly';
        }
        if ($day !== '*') {
            return 'monthly';
        }
        if ($hour !== '*') {
            return 'daily';
        }
        return 'hourly';
    }

    /**
     * Compute the next fire timestamp for a parsed schedule.
     * Returns null when the cron fields are not one of the four shapes the
     * panel writes (hourly/daily/weekly/monthly).
     */
    public static function nextRunAt(array $schedule, ?int $now = null): ?int
    {
        $now = $now ?? time();
        $minute = $schedule['minute'] ?? '*';
        $hour = $schedule['hour'] ?? '*';

        if (!is_numeric($minute)) {
            return null;
        }
        $minute = (int)$minute;

        switch ($schedule['frequency'] ?? self::frequencyFor($schedule)) {
            case 'hourly':
                $candidate = mktime((int)date('H', $now), $minute, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
                if ($candidate <= $now) {
                    $candidate += 3600;
                }
                return $candidate;

            case 'daily':
                if (!is_numeric($hour)) {
                    return null;
                }
                $candidate = mktime((int)$hour, $minute, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
                if ($candidate <= $now) {
                    $candidate = strtotime('+1 day', $candidate);
                }
                return $candidate;

            case 'weekly':
                if (!is_numeric($hour) || !is_numeric($schedule['weekday'] ?? '*')) {
                    return null;
                }
                $targetDow = ((int)$schedule['weekday']) % 7;
                $candidate = mktime((int)$hour, $minute, 0, (int)date('n', $now), (int)date('j', $now), (int)date('Y', $now));
                $currentDow = (int)date('w', $candidate);
                $daysAhead = ($targetDow - $currentDow + 7) % 7;
                $candidate = strtotime("+{$daysAhead} days", $candidate);
                if ($candidate <= $now) {
                    $candidate = strtotime('+7 days', $candidate);
                }
                return $candidate;

            case 'monthly':
                if (!is_numeric($hour) || !is_numeric($schedule['day'] ?? '*')) {
                    return null;
                }
                $dom = (int)$schedule['day'];
                $candidate = mktime((int)$hour, $minute, 0, (int)date('n', $now), $dom, (int)date('Y', $now));
                if ($candidate <= $now) {
                    $candidate = mktime((int)$hour, $minute, 0, (int)date('n', $now) + 1, $dom, (int)date('Y', $now));
                }
                return $candidate;
        }

        return null;
    }

    /**
     * Stable key identifying a schedule's workload (what it backs up and
     * where to), independent of time/frequency. Both the agent (when listing
     * schedules) and backup-runner.php (when recording outcomes) must derive
     * the identical key.
     */
    public static function runStateKeyFromArgs(array $args): string
    {
        $canonical = sprintf(
            'sites=%s;categories=%s;components=%s;destination=%s',
            $args['sites'] ?? '',
            $args['categories'] ?? '',
            $args['components'] ?? '',
            $args['destination'] ?? 'local'
        );
        return md5($canonical);
    }

    /**
     * Derive the run-state key from a cron command string.
     */
    public static function runStateKeyFromCommand(string $command): ?string
    {
        if (strpos($command, self::RUNNER_MARKER) === false) {
            return null;
        }

        $extract = function (string $flag) use ($command): string {
            return preg_match('/--' . $flag . '=([^\s]+)/', $command, $m) ? $m[1] : '';
        };

        return self::runStateKeyFromArgs([
            'sites' => $extract('sites'),
            'categories' => $extract('categories'),
            'components' => $extract('components'),
            'destination' => $extract('destination') ?: 'local',
        ]);
    }

    /**
     * Read the run-state file. Returns [] when missing/corrupt.
     */
    public static function readRunState(?string $stateFile = null): array
    {
        $stateFile = $stateFile ?? self::DEFAULT_STATE_FILE;
        if (!is_file($stateFile)) {
            return [];
        }
        $decoded = json_decode((string)@file_get_contents($stateFile), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Record a run outcome for a schedule key.
     * Statuses: 'running', 'success', 'degraded', 'failed'.
     */
    public static function writeRunState(string $key, string $status, string $message = '', ?string $stateFile = null): bool
    {
        $stateFile = $stateFile ?? self::DEFAULT_STATE_FILE;

        $dir = dirname($stateFile);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            return false;
        }

        $state = self::readRunState($stateFile);
        $state[$key] = [
            'status' => $status,
            'message' => $message,
            'time' => date('Y-m-d H:i:s'),
            'ts' => time(),
        ];

        // Bound the file: keep the most recently updated entries.
        if (count($state) > self::STATE_MAX_ENTRIES) {
            uasort($state, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
            $state = array_slice($state, 0, self::STATE_MAX_ENTRIES, true);
        }

        return @file_put_contents(
            $stateFile,
            json_encode($state, JSON_PRETTY_PRINT),
            LOCK_EX
        ) !== false;
    }

    /**
     * Normalize cron file content: strip trailing blank lines and guarantee
     * exactly one trailing newline (cron implementations may silently ignore
     * a final line without one).
     */
    public static function normalizeCronContent(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);
        while (!empty($lines) && trim((string)end($lines)) === '') {
            array_pop($lines);
        }
        if (empty($lines)) {
            return '';
        }
        return implode("\n", $lines) . "\n";
    }
}
