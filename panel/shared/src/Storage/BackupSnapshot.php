<?php

declare(strict_types=1);

namespace FlowOne\Storage;

/**
 * Phase 7 — One backup snapshot on disk.
 *
 * Snapshots live under `backup.destination_root/<kind>/<dateKey>/`
 * where:
 *   - dateKey is YYYY-MM-DD (ISO 8601 calendar date)
 *   - kind is 'daily' | 'weekly' | 'monthly'
 *
 * Layout:
 *
 *   /mnt/vps-backup/drive-snapshots/
 *     daily/
 *       2026-05-18/
 *         drive/
 *           {user_hash}/{file_md5}.bin
 *           ...
 *         manifest.json.sig
 *       2026-05-17/
 *         ...
 *     weekly/
 *       2026-05-17/        <- promoted from daily/2026-05-17 (a Sunday)
 *     monthly/
 *       2026-05-01/
 *
 * Promotion (daily -> weekly, weekly -> monthly) is a single rename;
 * the underlying directory contents are not copied. With --link-dest
 * pointing at the previous snapshot, day-N+1 reuses unchanged inodes
 * from day-N at zero space cost.
 *
 * Pure value object. All disk operations live in BackupRunner /
 * BackupRetentionService — this class only computes paths and parses
 * filenames.
 */
final class BackupSnapshot
{
    public const KIND_DAILY   = 'daily';
    public const KIND_WEEKLY  = 'weekly';
    public const KIND_MONTHLY = 'monthly';

    /** @var list<string> */
    public const ALL_KINDS = [self::KIND_DAILY, self::KIND_WEEKLY, self::KIND_MONTHLY];

    public function __construct(
        public readonly string $destinationRoot,
        public readonly string $kind,
        public readonly string $dateKey,
    ) {
        if (!in_array($kind, self::ALL_KINDS, true)) {
            throw new \InvalidArgumentException("BackupSnapshot: unknown kind '{$kind}'");
        }
        if (!self::isValidDateKey($dateKey)) {
            throw new \InvalidArgumentException("BackupSnapshot: invalid dateKey '{$dateKey}'");
        }
    }

    public static function isValidDateKey(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return false;
        $parts = explode('-', $s);
        return checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0]);
    }

    public static function todayKey(?\DateTimeZone $tz = null): string
    {
        $now = new \DateTimeImmutable('now', $tz);
        return $now->format('Y-m-d');
    }

    public function rootPath(): string
    {
        return rtrim($this->destinationRoot, '/') . '/' . $this->kind . '/' . $this->dateKey;
    }

    public function tmpPath(): string
    {
        return $this->rootPath() . '.tmp';
    }

    public function manifestPath(string $manifestName = 'manifest.json.sig'): string
    {
        return $this->rootPath() . '/' . $manifestName;
    }

    public function exists(): bool
    {
        return is_dir($this->rootPath());
    }

    /**
     * Resolve the most recent existing snapshot strictly older than
     * this one. Used as the rsync --link-dest source so the new
     * snapshot only stores diffs.
     *
     * @return BackupSnapshot|null
     */
    public function findLinkDestCandidate(): ?BackupSnapshot
    {
        // Look across all kinds for the most recent dateKey < ours.
        $best = null;
        foreach (self::ALL_KINDS as $kind) {
            $kindDir = rtrim($this->destinationRoot, '/') . '/' . $kind;
            if (!is_dir($kindDir)) continue;
            $children = @scandir($kindDir) ?: [];
            foreach ($children as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                if (!self::isValidDateKey($entry)) continue;
                if (strcmp($entry, $this->dateKey) >= 0) continue;
                if ($best === null || strcmp($entry, $best->dateKey) > 0) {
                    $best = new BackupSnapshot($this->destinationRoot, $kind, $entry);
                }
            }
        }
        return $best;
    }

    /**
     * Whether this $dateKey would qualify as the weekly anchor.
     * Default anchor: Sunday (PHP w=0).
     */
    public function matchesWeeklyAnchor(int $anchorDow = 0): bool
    {
        $dow = (int) (new \DateTimeImmutable($this->dateKey))->format('w');
        return $dow === $anchorDow;
    }

    public function matchesMonthlyAnchor(int $anchorDom = 1): bool
    {
        $dom = (int) (new \DateTimeImmutable($this->dateKey))->format('j');
        return $dom === $anchorDom;
    }

    public function toArray(): array
    {
        return [
            'kind'       => $this->kind,
            'date_key'   => $this->dateKey,
            'root_path'  => $this->rootPath(),
        ];
    }

    /**
     * Enumerate existing snapshots for a given kind, sorted by
     * dateKey ascending. Quiet on missing dir.
     *
     * @return list<BackupSnapshot>
     */
    public static function listKind(string $destinationRoot, string $kind): array
    {
        if (!in_array($kind, self::ALL_KINDS, true)) {
            throw new \InvalidArgumentException("listKind: unknown kind '{$kind}'");
        }
        $kindDir = rtrim($destinationRoot, '/') . '/' . $kind;
        if (!is_dir($kindDir)) return [];
        $entries = @scandir($kindDir) ?: [];
        $out = [];
        foreach ($entries as $e) {
            if ($e === '.' || $e === '..') continue;
            if (!self::isValidDateKey($e)) continue;
            $out[] = new BackupSnapshot($destinationRoot, $kind, $e);
        }
        usort($out, fn(self $a, self $b) => strcmp($a->dateKey, $b->dateKey));
        return $out;
    }
}
