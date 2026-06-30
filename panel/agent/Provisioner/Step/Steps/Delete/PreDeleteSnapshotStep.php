<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Delete;

use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * Capture a recoverable snapshot of the site BEFORE the destructive
 * steps of the DELETE saga run. Two artifacts are produced:
 *
 *   <snapshot_root>/<domain>/<job_id>/<db_name>.sql       (mysqldump)
 *   <snapshot_root>/<domain>/<job_id>/home.tar.gz         (home dir tar)
 *
 * Both write paths are recorded in StepState.data so the later
 * destructive steps (DatabaseDropStep, HomeDirRemoveStep) can verify
 * a snapshot exists before they proceed. The per-job folder means
 * resumes overwrite themselves (idempotent) but distinct jobs never
 * stomp on each other.
 *
 * Configuration knobs (payload):
 *   - snapshot_root=/abs/path            - root override (tests/server).
 *                                          Default: /var/www/vps-admin/storage/snapshots
 *   - skip_snapshot=true                 - skip everything (operator
 *                                          data-loss waiver; matches
 *                                          DatabaseDropStep/HomeDirRemoveStep).
 *   - skip_db_snapshot=true              - skip mysqldump only.
 *   - skip_home_snapshot=true            - skip home tar only.
 *   - snapshot_timeout_seconds=N         - mysqldump + tar timeout (default 600).
 *
 * Idempotence:
 *   - check() returns true iff the step has previously recorded a
 *     snapshot for THIS job (state.completed && state.data['job_id']
 *     matches). Resumes pick up the existing snapshot, never re-dump.
 *
 * Compensation: DEGRADE_ONLY.
 *   We never delete the snapshot on compensate - it's the recovery
 *   artifact the operator may need to restore from.
 */
final class PreDeleteSnapshotStep extends AbstractStep
{
    private const DEFAULT_SNAPSHOT_ROOT = '/var/www/vps-admin/storage/snapshots';
    private const DEFAULT_TIMEOUT_SECONDS = 600;

    public function name(): string
    {
        return StepName::PRE_DELETE_SNAPSHOT;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        // Operator waiver - skip and treat as done.
        if (($ctx->payload['skip_snapshot'] ?? false) === true) {
            return true;
        }
        // Already snapshotted in THIS job?
        $cachedJobId = $state->data['job_id'] ?? null;
        if ($state->isComplete() && (int) $cachedJobId === $ctx->jobId) {
            return true;
        }
        return false;
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        if (($ctx->payload['skip_snapshot'] ?? false) === true) {
            return StepResult::success(
                $state->mergeData([
                    'job_id' => $ctx->jobId,
                    'db_skipped' => true,
                    'home_skipped' => true,
                    'skipped_by_operator' => true,
                ])->withCompleted(),
                [StepEvent::warning('snapshot skipped by operator (skip_snapshot=true)')],
                ['db_dumped' => 0, 'home_archived' => 0],
            );
        }

        $events = [];
        $snapshotDir = $this->snapshotDir($ctx);

        try {
            $ctx->requireAdapters()->fs->ensureDirectory($snapshotDir, 0700);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state,
                "could not create snapshot dir {$snapshotDir}: " . $e->getMessage(),
                $events,
            );
        }
        $events[] = StepEvent::info('snapshot directory ready', ['dir' => $snapshotDir]);

        $timeout = (int) ($ctx->payload['snapshot_timeout_seconds'] ?? self::DEFAULT_TIMEOUT_SECONDS);
        if ($timeout < 10) {
            $timeout = self::DEFAULT_TIMEOUT_SECONDS;
        }

        // ── DB dump ────────────────────────────────────────────
        $dbResult = $this->snapshotDatabase($ctx, $snapshotDir, $timeout, $events);
        if (isset($dbResult['error'])) {
            return StepResult::failure(
                $state->mergeData(['snapshot_dir' => $snapshotDir, 'job_id' => $ctx->jobId]),
                $dbResult['error'],
                $events,
            );
        }

        // ── Home dir tar ───────────────────────────────────────
        $homeResult = $this->snapshotHomeDir($ctx, $snapshotDir, $timeout, $events);
        if (isset($homeResult['error'])) {
            return StepResult::failure(
                $state->mergeData(array_merge(
                    ['snapshot_dir' => $snapshotDir, 'job_id' => $ctx->jobId],
                    $dbResult,
                )),
                $homeResult['error'],
                $events,
            );
        }

        $events[] = StepEvent::info('snapshot complete', [
            'dir' => $snapshotDir,
            'db_dumped' => !($dbResult['db_skipped'] ?? false),
            'home_archived' => !($homeResult['home_skipped'] ?? false),
        ]);

        return StepResult::success(
            $state->mergeData(array_merge(
                ['snapshot_dir' => $snapshotDir, 'job_id' => $ctx->jobId],
                $dbResult,
                $homeResult,
            ))->withCompleted(),
            $events,
            [
                'db_dumped' => ($dbResult['db_skipped'] ?? false) ? 0 : 1,
                'home_archived' => ($homeResult['home_skipped'] ?? false) ? 0 : 1,
            ],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        return StepResult::success(
            $state,
            [StepEvent::info(
                'compensate: snapshot retained as recovery artifact (DEGRADE_ONLY)',
                ['dir' => $state->data['snapshot_dir'] ?? null]
            )]
        );
    }

    private function snapshotDir(SiteContext $ctx): string
    {
        $root = $ctx->payload['snapshot_root'] ?? self::DEFAULT_SNAPSHOT_ROOT;
        if (!is_string($root) || $root === '' || $root[0] !== '/') {
            throw new \RuntimeException("snapshot_root must be absolute, got: " . var_export($root, true));
        }
        return rtrim($root, '/') . '/' . $ctx->domain() . '/' . $ctx->jobId;
    }

    /**
     * @param list<StepEvent> $events  Appended by reference.
     * @return array{db_dump_path?:string,db_dump_bytes?:int,db_name?:string,db_skipped?:bool,error?:string}
     */
    private function snapshotDatabase(SiteContext $ctx, string $dir, int $timeout, array &$events): array
    {
        if (($ctx->payload['skip_db_snapshot'] ?? false) === true) {
            $events[] = StepEvent::warning('db snapshot skipped by operator (skip_db_snapshot=true)');
            return ['db_skipped' => true];
        }

        $mysql = $ctx->requireAdapters()->mysql;

        $dbName = $this->resolveDbName($ctx);
        if ($dbName === null) {
            $events[] = StepEvent::info('no db_name resolvable; db snapshot skipped');
            return ['db_skipped' => true];
        }

        try {
            $exists = $mysql->databaseExists($dbName);
        } catch (\Throwable $e) {
            return ['error' => "databaseExists() failed for '{$dbName}': " . $e->getMessage()];
        }
        if (!$exists) {
            $events[] = StepEvent::info('database absent at snapshot time; db snapshot skipped', [
                'db' => $dbName,
            ]);
            return ['db_skipped' => true, 'db_name' => $dbName];
        }

        $outPath = $dir . '/' . $dbName . '.sql';
        $events[] = StepEvent::info('starting mysqldump', [
            'db' => $dbName, 'out' => $outPath, 'timeout_s' => $timeout,
        ]);

        try {
            $bytes = $mysql->dumpDatabase($dbName, $outPath, $timeout);
        } catch (\Throwable $e) {
            return ['error' => "mysqldump failed for '{$dbName}': " . $e->getMessage()];
        }
        $events[] = StepEvent::info('mysqldump done', ['db' => $dbName, 'bytes' => $bytes]);

        return [
            'db_dump_path' => $outPath,
            'db_dump_bytes' => $bytes,
            'db_name' => $dbName,
        ];
    }

    /**
     * @param list<StepEvent> $events  Appended by reference.
     * @return array{home_tar_path?:string,home_tar_bytes?:int,home?:string,home_skipped?:bool,error?:string}
     */
    private function snapshotHomeDir(SiteContext $ctx, string $dir, int $timeout, array &$events): array
    {
        if (($ctx->payload['skip_home_snapshot'] ?? false) === true) {
            $events[] = StepEvent::warning('home snapshot skipped by operator (skip_home_snapshot=true)');
            return ['home_skipped' => true];
        }

        $home = $this->resolveHomeDir($ctx);
        $fs = $ctx->requireAdapters()->fs;
        if (!$fs->isDirectory($home)) {
            $events[] = StepEvent::info('home dir absent at snapshot time; home snapshot skipped', [
                'home' => $home,
            ]);
            return ['home_skipped' => true, 'home' => $home];
        }

        $tarPath = $dir . '/home.tar.gz';
        $events[] = StepEvent::info('starting home tar', [
            'home' => $home, 'out' => $tarPath, 'timeout_s' => $timeout,
        ]);

        $parent = dirname($home);
        $leaf = basename($home);
        if ($parent === '' || $leaf === '' || $leaf === '/' || $leaf === '.') {
            return ['error' => "refusing to tar a root-equivalent path: {$home}"];
        }

        // tar -C parent -czf out.tar.gz leaf
        // The -C indirection means we don't bake absolute paths into
        // the archive (so restore can unpack to a different prefix).
        $runner = $ctx->requireAdapters()->runner;
        $args = ['-C', $parent, '-czf', $tarPath, '--', $leaf];
        $r = $runner->run('/bin/tar', $args, null, $timeout);
        if (!$r->isSuccess()) {
            return ['error' => "tar failed for '{$home}': " . $r->summary()];
        }
        $size = $fs->exists($tarPath) ? (int) (@filesize($tarPath) ?: 0) : 0;
        $events[] = StepEvent::info('home tar done', ['home' => $home, 'bytes' => $size]);

        return [
            'home_tar_path' => $tarPath,
            'home_tar_bytes' => $size,
            'home' => $home,
        ];
    }

    /**
     * DB name resolution matches DatabaseDropStep's order so the two
     * always agree on which DB they're talking about.
     */
    private function resolveDbName(SiteContext $ctx): ?string
    {
        $payload = $ctx->payload;
        if (!empty($payload['db_name']) && is_string($payload['db_name'])) {
            return $payload['db_name'];
        }
        $row = $ctx->siteRow;
        if (!empty($row['db_name']) && is_string($row['db_name'])) {
            return $row['db_name'];
        }
        $stateJson = $row['state'] ?? null;
        $map = is_string($stateJson) ? json_decode($stateJson, true) : (is_array($stateJson) ? $stateJson : null);
        if (is_array($map)
            && isset($map[StepName::DATABASE_CREATE]['data']['db_name'])
            && is_string($map[StepName::DATABASE_CREATE]['data']['db_name'])
        ) {
            return $map[StepName::DATABASE_CREATE]['data']['db_name'];
        }
        // Best-effort: derive. Returns the name the create step would
        // have used; databaseExists() short-circuits if nothing was
        // ever created.
        return $this->deriveDbFromDomain($ctx->domain());
    }

    private function resolveHomeDir(SiteContext $ctx): string
    {
        $payload = $ctx->payload;
        if (!empty($payload['home_dir']) && is_string($payload['home_dir'])) {
            return $payload['home_dir'];
        }
        $row = $ctx->siteRow;
        if (!empty($row['home_dir']) && is_string($row['home_dir'])) {
            return $row['home_dir'];
        }
        $stateJson = $row['state'] ?? null;
        $map = is_string($stateJson) ? json_decode($stateJson, true) : (is_array($stateJson) ? $stateJson : null);
        if (is_array($map)
            && isset($map[StepName::HOME_DIR_CREATE]['data']['home'])
            && is_string($map[StepName::HOME_DIR_CREATE]['data']['home'])
        ) {
            return $map[StepName::HOME_DIR_CREATE]['data']['home'];
        }
        return '/home/' . $ctx->domain();
    }

    /**
     * MUST match DatabaseCreateStep::deriveFromDomain() byte-for-byte.
     */
    private function deriveDbFromDomain(string $domain): string
    {
        $sanitized = strtolower($domain);
        $sanitized = preg_replace('/[^a-z0-9_]/', '_', $sanitized) ?? '';
        $sanitized = trim($sanitized, '_');
        if ($sanitized === '') {
            throw new \RuntimeException("Cannot derive db name from domain: '{$domain}'");
        }
        $candidate = 'flowone_' . $sanitized;
        if (strlen($candidate) > 64) {
            $hash = substr(hash('sha1', $candidate), 0, 6);
            $prefix = rtrim(substr($candidate, 0, 64 - 7), '_');
            $candidate = $prefix . '_' . $hash;
        }
        return $candidate;
    }
}
