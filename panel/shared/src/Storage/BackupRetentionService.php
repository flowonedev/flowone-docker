<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 7 — Snapshot retention + promotion policy.
 *
 * Policy (configurable via backup.retention.*):
 *
 *   - daily:   keep the N most recent daily snapshots
 *   - weekly:  if today is the weekly anchor (default Sunday) AND a
 *              weekly snapshot for today does not exist, promote
 *              today's daily snapshot to weekly via rename.
 *              Then keep only the M most recent weeklies.
 *   - monthly: if today is the monthly anchor (default 1st) AND a
 *              monthly for today does not exist, promote today's
 *              snapshot (preferring the weekly-promoted version if
 *              already created, else the daily) to monthly via rename.
 *              Then keep only the K most recent monthlies.
 *
 * Pruning = directory removal. Files that are hardlinked into both
 * the to-be-removed snapshot and any retained snapshot survive (inode
 * count drops by 1).
 *
 * Promotion = rename. The source snapshot is moved, not copied.
 * Subsequent --link-dest queries treat the renamed directory as
 * any other snapshot (BackupSnapshot::findLinkDestCandidate walks
 * all three kind directories).
 *
 * NEVER deletes anything outside destination_root. NEVER deletes
 * snapshots that are still within the keep window.
 */
final class BackupRetentionService
{
    public function __construct(
        private array            $config,
        private OperationJournal $journal,
    ) {}

    public static function build(array $config, OperationJournal $journal): self
    {
        return new self($config, $journal);
    }

    /**
     * Apply retention. dryRun reports what WOULD happen without
     * mutating disk. dateKey defaults to today (UTC).
     *
     * @return array{
     *   promoted: list<array>, pruned: list<array>, kept: array,
     *   dry_run: bool, errors: list<string>
     * }
     */
    public function apply(?string $dateKey = null, bool $dryRun = false): array
    {
        $dateKey = $dateKey ?? BackupSnapshot::todayKey(new \DateTimeZone('UTC'));
        $dest    = (string) ($this->config['backup']['destination_root'] ?? '');
        if ($dest === '' || !is_dir($dest)) {
            return ['promoted' => [], 'pruned' => [], 'kept' => [], 'dry_run' => $dryRun,
                'errors' => ["destination_root missing or empty: {$dest}"]];
        }

        $r = (array) ($this->config['backup']['retention'] ?? []);
        $keepDaily   = max(1, (int) ($r['keep_daily']         ?? 7));
        $keepWeekly  = max(1, (int) ($r['keep_weekly']        ?? 4));
        $keepMonthly = max(1, (int) ($r['keep_monthly']       ?? 12));
        $dowAnchor   = (int) ($r['weekly_anchor_dow']         ?? 0);
        $domAnchor   = (int) ($r['monthly_anchor_dom']        ?? 1);

        $promoted = []; $errors = [];

        // --- 1. Promote daily -> weekly (only on anchor day) ---------
        $today = new BackupSnapshot($dest, BackupSnapshot::KIND_DAILY, $dateKey);
        if ($today->matchesWeeklyAnchor($dowAnchor) && $today->exists()) {
            $target = new BackupSnapshot($dest, BackupSnapshot::KIND_WEEKLY, $dateKey);
            if (!$target->exists()) {
                $p = $this->promote($today, $target, $dryRun);
                if ($p['ok']) $promoted[] = $p; else $errors[] = $p['reason'];
            }
        }

        // --- 2. Promote weekly|daily -> monthly (only on anchor day) -
        $todayMonthly = new BackupSnapshot($dest, BackupSnapshot::KIND_MONTHLY, $dateKey);
        if ($todayMonthly->matchesMonthlyAnchor($domAnchor) && !$todayMonthly->exists()) {
            // Prefer the weekly-promoted version (already on weekly anchor)
            // else fall back to daily.
            $source = new BackupSnapshot($dest, BackupSnapshot::KIND_WEEKLY, $dateKey);
            if (!$source->exists()) {
                $source = new BackupSnapshot($dest, BackupSnapshot::KIND_DAILY, $dateKey);
            }
            if ($source->exists()) {
                $p = $this->promote($source, $todayMonthly, $dryRun);
                if ($p['ok']) $promoted[] = $p; else $errors[] = $p['reason'];
            }
        }

        // --- 3. Prune each kind to its keep count --------------------
        $pruned = [];
        foreach ([
            BackupSnapshot::KIND_DAILY   => $keepDaily,
            BackupSnapshot::KIND_WEEKLY  => $keepWeekly,
            BackupSnapshot::KIND_MONTHLY => $keepMonthly,
        ] as $kind => $keep) {
            $existing = BackupSnapshot::listKind($dest, $kind);
            $count = count($existing);
            if ($count <= $keep) continue;
            // Sorted ascending; oldest are at the front.
            $toPrune = array_slice($existing, 0, $count - $keep);
            foreach ($toPrune as $snap) {
                $p = $this->prune($snap, $dest, $dryRun);
                if ($p['ok']) $pruned[] = $p; else $errors[] = $p['reason'];
            }
        }

        // --- 4. Report ----------------------------------------------
        $kept = [];
        foreach ([BackupSnapshot::KIND_DAILY, BackupSnapshot::KIND_WEEKLY, BackupSnapshot::KIND_MONTHLY] as $kind) {
            $kept[$kind] = array_map(fn(BackupSnapshot $s) => $s->dateKey, BackupSnapshot::listKind($dest, $kind));
        }

        if (!$dryRun) {
            $this->journal->record('backup_retention_apply', [
                'promoted'    => $promoted,
                'pruned'      => $pruned,
                'kept_counts' => array_map('count', $kept),
            ]);
        }

        return [
            'promoted' => $promoted,
            'pruned'   => $pruned,
            'kept'     => $kept,
            'dry_run'  => $dryRun,
            'errors'   => $errors,
        ];
    }

    private function promote(BackupSnapshot $from, BackupSnapshot $to, bool $dryRun): array
    {
        $action = [
            'kind'     => 'promote',
            'from'     => $from->toArray(),
            'to'       => $to->toArray(),
            'dry_run'  => $dryRun,
            'ok'       => false,
            'reason'   => null,
        ];
        if (!$from->exists()) {
            $action['reason'] = "source_missing: {$from->rootPath()}";
            return $action;
        }
        if ($to->exists()) {
            $action['reason'] = "destination_exists: {$to->rootPath()}";
            return $action;
        }
        if ($dryRun) { $action['ok'] = true; return $action; }
        $parentDir = dirname($to->rootPath());
        if (!is_dir($parentDir) && !@mkdir($parentDir, 0750, true)) {
            $action['reason'] = "mkdir_failed: {$parentDir}";
            return $action;
        }
        if (!@rename($from->rootPath(), $to->rootPath())) {
            $action['reason'] = "rename_failed: {$from->rootPath()} -> {$to->rootPath()}";
            return $action;
        }
        $action['ok'] = true;
        return $action;
    }

    /**
     * Recursive snapshot removal. Refuses to delete anything that's
     * not under destination_root (defence-in-depth against config
     * mistakes pointing the destination at /).
     */
    private function prune(BackupSnapshot $snap, string $destRoot, bool $dryRun): array
    {
        $action = [
            'kind'    => 'prune',
            'target'  => $snap->toArray(),
            'dry_run' => $dryRun,
            'ok'      => false,
            'reason'  => null,
        ];
        $abs = $snap->rootPath();
        $destRoot = rtrim($destRoot, '/');
        if (!str_starts_with($abs, $destRoot . '/')) {
            $action['reason'] = "refused_outside_dest_root: {$abs}";
            return $action;
        }
        if (!is_dir($abs)) {
            $action['reason'] = "missing: {$abs}";
            return $action;
        }
        if ($dryRun) { $action['ok'] = true; return $action; }
        if (!$this->rmrf($abs)) {
            $action['reason'] = "rmrf_failed: {$abs}";
            return $action;
        }
        $action['ok'] = true;
        return $action;
    }

    private function rmrf(string $path): bool
    {
        if (is_file($path) || is_link($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) return true;
        $children = @scandir($path) ?: [];
        foreach ($children as $c) {
            if ($c === '.' || $c === '..') continue;
            if (!$this->rmrf($path . '/' . $c)) return false;
        }
        return @rmdir($path);
    }
}
