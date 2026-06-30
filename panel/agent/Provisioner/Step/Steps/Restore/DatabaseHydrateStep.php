<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step\Steps\Restore;

use VpsAdmin\Agent\Provisioner\Step\AbstractStep;
use VpsAdmin\Agent\Provisioner\Step\CompensationPolicy;
use VpsAdmin\Agent\Provisioner\Step\Saga\StepName;
use VpsAdmin\Agent\Provisioner\Step\SiteContext;
use VpsAdmin\Agent\Provisioner\Step\StepEvent;
use VpsAdmin\Agent\Provisioner\Step\StepResult;
use VpsAdmin\Agent\Provisioner\Step\StepState;

/**
 * Replay the archived <db>.sql into the freshly-created database.
 * Runs AFTER DatabaseGrantStep so the per-site DB user exists and
 * has full privileges, BUT we still use the admin credentials in
 * MysqlAdapter for the import (the admin can `CREATE DEFINER` etc
 * which mysqldumps from older versions often need).
 *
 * Idempotence:
 *   - check() returns true iff state.completed && state.data['db']
 *     matches the resolved db name AND the dump SHA-1 we recorded
 *     last time matches the dump on disk now. If the dump file
 *     changed between attempts, we re-restore.
 *
 * Compensation: SAFE_ROLLBACK; no-op.
 *   The destructive teardown on RESTORE compensation drops the DB
 *   entirely.
 *
 * Payload knobs:
 *   - skip_db_hydrate=true              skip silently.
 *   - db_dump_path=/abs/path            override the resolved path
 *                                        (default: archive_path/<db>.sql).
 *   - restore_timeout_seconds=N         default 1800.
 *
 * Site-without-db case:
 *   - If the site has no db_name (resolved across payload + siteRow),
 *     the step is a successful no-op. Mirrors the way DatabaseCreate
 *     is no-oped in that case.
 */
final class DatabaseHydrateStep extends AbstractStep
{
    private const DEFAULT_TIMEOUT_SECONDS = 1800;

    public function name(): string
    {
        return StepName::DATABASE_HYDRATE;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::SAFE_ROLLBACK;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        if (($ctx->payload['skip_db_hydrate'] ?? false) === true) {
            return true;
        }
        $dbName = $this->resolveDbName($ctx);
        if ($dbName === null) {
            // No db to hydrate; treat as already done.
            return true;
        }
        if (!$state->isComplete()) {
            return false;
        }
        $cachedDb = $state->data['db'] ?? null;
        $cachedSha = $state->data['dump_sha1'] ?? null;
        if (!is_string($cachedDb) || $cachedDb !== $dbName || !is_string($cachedSha)) {
            return false;
        }
        $dumpPath = $this->resolveDumpPath($ctx, $dbName);
        if ($dumpPath === null) {
            return false;
        }
        $currentSha = @sha1_file($dumpPath);
        return is_string($currentSha) && $currentSha === $cachedSha;
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        if (($ctx->payload['skip_db_hydrate'] ?? false) === true) {
            return StepResult::success(
                $state->mergeData(['skipped_by_operator' => true])->withCompleted(),
                [StepEvent::warning('db hydrate skipped by operator (skip_db_hydrate=true)')],
            );
        }

        $dbName = $this->resolveDbName($ctx);
        if ($dbName === null) {
            return StepResult::success(
                $state->mergeData(['db' => null, 'no_db' => true])->withCompleted(),
                [StepEvent::info('site has no db_name; database hydrate skipped')],
            );
        }

        $fs = $ctx->requireAdapters()->fs;
        $mysql = $ctx->requireAdapters()->mysql;

        $dumpPath = $this->resolveDumpPath($ctx, $dbName);
        if ($dumpPath === null) {
            return StepResult::failure(
                $state,
                'database_hydrate: archive_path missing and no explicit db_dump_path provided'
            );
        }
        if (!$fs->isFile($dumpPath)) {
            return StepResult::failure(
                $state,
                "database_hydrate: dump file not found at {$dumpPath}"
            );
        }

        $events = [StepEvent::info('starting mysql restore', [
            'db' => $dbName, 'dump' => $dumpPath,
        ])];

        $timeout = (int) ($ctx->payload['restore_timeout_seconds'] ?? self::DEFAULT_TIMEOUT_SECONDS);
        if ($timeout < 60) {
            $timeout = self::DEFAULT_TIMEOUT_SECONDS;
        }

        try {
            $bytes = $mysql->restoreDatabase($dbName, $dumpPath, $timeout);
        } catch (\Throwable $e) {
            return StepResult::failure(
                $state,
                "mysql restore failed: " . $e->getMessage(),
                $events,
            );
        }

        $events[] = StepEvent::info('mysql restore complete', [
            'db' => $dbName, 'bytes' => $bytes,
        ]);

        $sha = @sha1_file($dumpPath);
        if (!is_string($sha)) {
            $sha = null;
        }

        return StepResult::success(
            $state->mergeData([
                'db' => $dbName,
                'dump_path' => $dumpPath,
                'dump_bytes' => $bytes,
                'dump_sha1' => $sha,
                'restored_at' => time(),
            ])->withCompleted(),
            $events,
            ['db_bytes_restored' => $bytes],
        );
    }

    private function resolveDbName(SiteContext $ctx): ?string
    {
        if (!empty($ctx->payload['db_name']) && is_string($ctx->payload['db_name'])) {
            return $ctx->payload['db_name'];
        }
        $row = $ctx->siteRow;
        if (!empty($row['db_name']) && is_string($row['db_name'])) {
            return $row['db_name'];
        }
        return null;
    }

    private function resolveDumpPath(SiteContext $ctx, string $dbName): ?string
    {
        $payload = $ctx->payload;
        if (!empty($payload['db_dump_path']) && is_string($payload['db_dump_path'])) {
            return $payload['db_dump_path'];
        }
        $archive = $payload['archive_path'] ?? null;
        if (is_string($archive) && $archive !== '') {
            return rtrim($archive, '/') . '/' . $dbName . '.sql';
        }
        return null;
    }
}
