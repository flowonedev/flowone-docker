<?php

namespace Webmail\Services;

/**
 * DriveVersioningService - File version lifecycle for the Drive system.
 *
 * Owns everything below drive_file_versions: creating version rows when a
 * file's content is replaced, listing/restoring/deleting versions, pin and
 * label metadata, per-user usage accounting, and the smart-thinning
 * retention engine.
 *
 * Quota contract: version bytes COUNT toward the user's Drive quota.
 * Every path that turns the current file into a version keeps the old
 * bytes on disk and charges the NEW content's full size; deleting or
 * pruning a version refunds its size.
 *
 * DriveService stays the owner of path resolution, quota bookkeeping and
 * storage tiering; this service consumes that API and never duplicates it.
 */
class DriveVersioningService
{
    public const DEFAULT_KEEP_ALL_HOURS    = 24;  // keep every version this fresh
    public const DEFAULT_DAILY_WINDOW_DAYS = 30;  // then one per day up to here
    public const DEFAULT_MAX_VERSIONS      = 50;  // hard cap per file

    private \PDO $db;
    private array $config;
    private DriveService $drive;

    private int $keepAllHours;
    private int $dailyWindowDays;
    private int $maxVersions;

    public function __construct(array $config, DriveService $drive)
    {
        $this->config = $config;
        $this->drive  = $drive;
        $this->db     = $drive->getDb();

        $vcfg = $config['drive']['versioning'] ?? [];
        $this->keepAllHours    = max(1, (int)($vcfg['keep_all_hours'] ?? self::DEFAULT_KEEP_ALL_HOURS));
        $this->dailyWindowDays = max(1, (int)($vcfg['daily_window_days'] ?? self::DEFAULT_DAILY_WINDOW_DAYS));
        $this->maxVersions     = max(1, (int)($vcfg['max_versions_per_file'] ?? self::DEFAULT_MAX_VERSIONS));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Version creation
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Snapshot the file's CURRENT content as a history row.
     *
     * Two byte-ownership models exist:
     *   - Server-managed files: bytes live in the hashed user dir under
     *     drive_files.filename. The caller is about to point drive_files
     *     at NEW content, so the row simply adopts the existing filename -
     *     a pointer move, no copy, no quota change.
     *   - Desktop NAS-registered files (storage_location='nas' with a
     *     nas_relative_path): drive_files.filename is bookkeeping only and
     *     the real bytes sit in the user's visible NAS tree, which gets
     *     OVERWRITTEN IN PLACE. The bytes are copied into the hashed user
     *     dir first (quota charged for the copy) so the version is real.
     *
     * Returns ['version_id' => int, 'version_number' => int] or null.
     */
    public function archiveCurrentAsVersion(string $email, array $file): ?array
    {
        $email = strtolower($email);
        $fileId = (int)$file['id'];
        $currentVersion = (int)($file['current_version'] ?? 1);

        $isNasRegistered = ($file['storage_location'] ?? '') === 'nas' && !empty($file['nas_relative_path']);

        $versionFilename = (string)$file['filename'];
        $versionTier = $file['storage_location'] ?? null;
        $copiedPath = null;

        if ($isNasRegistered) {
            $sourcePath = $this->drive->getFilePath($email, $fileId);
            if (!$sourcePath || !is_file($sourcePath)) {
                error_log("DriveVersioningService archiveCurrentAsVersion: NAS bytes unreadable for file {$fileId}");
                return null;
            }

            $ext = pathinfo($file['original_name'], PATHINFO_EXTENSION);
            $versionFilename = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
            $copiedPath = $this->drive->getUserPath($email) . '/' . $versionFilename;

            if (!copy($sourcePath, $copiedPath)) {
                error_log("DriveVersioningService archiveCurrentAsVersion: copy failed {$sourcePath} -> {$copiedPath}");
                return null;
            }
            $versionTier = $this->drive->resolveStorageLocation($copiedPath);
        }

        // Guard against legacy numbering drift (the old NAS path derived
        // numbers from MAX(version_number) instead of current_version).
        $stmt = $this->db->prepare('SELECT COALESCE(MAX(version_number), 0) FROM drive_file_versions WHERE file_id = ?');
        $stmt->execute([$fileId]);
        $maxExisting = (int)$stmt->fetchColumn();
        $archiveNumber = max($currentVersion, $maxExisting + 1);

        try {
            $stmt = $this->db->prepare('
                INSERT INTO drive_file_versions
                    (file_id, version_number, filename, size, storage_location, mime_type, checksum, modified_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()))
            ');
            $stmt->execute([
                $fileId,
                $archiveNumber,
                $versionFilename,
                (int)$file['size'],
                $versionTier,
                $file['mime_type'] ?? null,
                $file['checksum'] ?? null,
                $file['last_modified_by'] ?? $file['created_by'] ?? $email,
                $file['updated_at'] ?? $file['created_at'] ?? null,
            ]);
        } catch (\PDOException $e) {
            error_log('DriveVersioningService archiveCurrentAsVersion error: ' . $e->getMessage());
            if ($copiedPath) @unlink($copiedPath);
            return null;
        }

        $versionId = (int)$this->db->lastInsertId();

        if ($isNasRegistered && $copiedPath) {
            // The archive copy is new physical data.
            $this->drive->updateUsedSpace($email, (int)$file['size']);
            // If the copy fell back to local disk, queue it for NAS.
            $this->drive->enqueueNasMigrationIfNeeded($fileId, $email, $copiedPath);
        }

        // Any queued local->NAS migration for these bytes must follow them
        // into the versions table (matched by filename).
        $this->repointPendingNasMigration($fileId, $versionFilename, $versionId);

        return ['version_id' => $versionId, 'version_number' => $archiveNumber];
    }

    /**
     * Path C (desktop direct-NAS write): called by the client BEFORE it
     * overwrites the NAS file in place. Archives the current content (a
     * real byte copy for NAS-registered files) and bumps current_version
     * so the subsequent metadata update lands on the new number.
     */
    public function snapshotCurrentVersion(string $email, int $fileId): ?array
    {
        $email = strtolower($email);

        $file = $this->drive->getFile($email, $fileId);
        if (!$file) return null;

        $archive = $this->archiveCurrentAsVersion($email, $file);
        if (!$archive) return null;

        $this->db->prepare('UPDATE drive_files SET current_version = ? WHERE id = ?')
            ->execute([$archive['version_number'] + 1, $fileId]);

        $this->pruneFileVersions($email, $fileId);

        return $archive;
    }

    /**
     * Path A (web/desktop API upload): same filename re-uploaded. Archives
     * the current content, stores the new bytes under a fresh random name
     * and bumps current_version.
     */
    public function createNewVersion(string $email, int $fileId, array $uploadedFile): ?array
    {
        $email = strtolower($email);
        $file = $this->drive->getFile($email, $fileId);
        if (!$file) {
            throw new \RuntimeException('Cannot save new version: original file record not found');
        }

        $size = (int)filesize($uploadedFile['tmp_name']);

        // A new version keeps the previous version's bytes on disk, so the new
        // content is charged as a net addition. This can fail the quota check
        // even when overwriting a same-named file looks "free" to the client -
        // a common reason an upload that passes the client check fails here.
        if (!$this->drive->hasQuota($email, $size)) {
            error_log('DriveVersioningService: quota exceeded for version upload');
            throw new \RuntimeException(
                'Not enough storage to save a new version (the previous version is kept; needs ' .
                DriveService::formatSize($size) . ' more)'
            );
        }
        $this->drive->maybeAdmitUpload($size);

        $archive = $this->archiveCurrentAsVersion($email, $file);
        if (!$archive) {
            throw new \RuntimeException('Failed to archive the current version (storage may be temporarily unavailable)');
        }

        try {
            $extension = pathinfo($uploadedFile['name'], PATHINFO_EXTENSION);
            $newFilename = bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');

            $userPath = $this->drive->getUserPath($email);
            $targetPath = $userPath . '/' . $newFilename;

            if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
                $err = error_get_last()['message'] ?? 'unknown';
                error_log('DriveVersioningService: failed to move version file: ' . $err);
                throw new \RuntimeException('Failed to save the new version to storage');
            }

            $newVersion = $archive['version_number'] + 1;
            $mimeType = $uploadedFile['type'] ?? mime_content_type($targetPath) ?? 'application/octet-stream';
            $storageLocation = $this->drive->resolveStorageLocation();

            $this->db->prepare('
                UPDATE drive_files
                SET filename = ?, size = ?, mime_type = ?, current_version = ?, last_modified_by = ?, storage_location = ?, updated_at = NOW()
                WHERE user_email = ? AND id = ?
            ')->execute([$newFilename, $size, $mimeType, $newVersion, $email, $storageLocation, $email, $fileId]);

            $this->drive->enqueueNasMigrationIfNeeded($fileId, $email, $targetPath);
            $this->drive->updateUsedSpace($email, $size);

            $this->pruneFileVersions($email, $fileId);

            return $this->drive->getFile($email, $fileId);
        } catch (\PDOException $e) {
            error_log('DriveVersioningService createNewVersion error: ' . $e->getMessage());
            throw new \RuntimeException('Database error while saving the new version');
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Listing / reading
    // ─────────────────────────────────────────────────────────────────────

    /**
     * All versions of a file, newest first, with the live content
     * prepended as a pseudo-row (is_current = true, id = null).
     */
    public function getFileVersions(string $email, int $fileId): array
    {
        $email = strtolower($email);

        $file = $this->drive->getFile($email, $fileId);
        if (!$file) return [];

        $stmt = $this->db->prepare('
            SELECT * FROM drive_file_versions
            WHERE file_id = ?
            ORDER BY version_number DESC
        ');
        $stmt->execute([$fileId]);
        $versions = $stmt->fetchAll();

        array_unshift($versions, [
            'id' => null,
            'file_id' => $fileId,
            'version_number' => $file['current_version'] ?? 1,
            'filename' => $file['filename'],
            'size' => $file['size'],
            'storage_location' => $file['storage_location'] ?? null,
            'mime_type' => $file['mime_type'] ?? null,
            'checksum' => $file['checksum'] ?? null,
            'label' => null,
            'is_pinned' => 0,
            'modified_by' => $file['last_modified_by'] ?? $file['created_by'] ?? $email,
            'created_at' => $file['updated_at'] ?? $file['created_at'],
            'is_current' => true,
        ]);

        return $this->addEditingDurationsToVersions($fileId, $file, $versions);
    }

    /**
     * Join time-tracking data so each version shows how long it was edited.
     * Version N's editing window is (version N-1 created_at, version N created_at].
     */
    private function addEditingDurationsToVersions(int $fileId, array $file, array $versions): array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT SUM(duration_seconds) as total, tracked_date
                FROM webmail_client_time_tracking
                WHERE entity_id = ?
                AND activity_type IN ('document_edit', 'document_open')
                GROUP BY tracked_date
                ORDER BY tracked_date
            ");
            $stmt->execute([(string)$fileId]);
            $timeByDate = $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
        } catch (\Exception $e) {
            error_log('DriveVersioningService: time tracking query failed: ' . $e->getMessage());
            return $versions;
        }

        for ($i = 0; $i < count($versions); $i++) {
            $endDate = $versions[$i]['created_at'];
            $startDate = ($i < count($versions) - 1)
                ? $versions[$i + 1]['created_at']
                : $file['created_at'];

            $totalSeconds = 0;
            foreach ($timeByDate as $date => $seconds) {
                $trackDate = $date . ' 00:00:00';
                if ($trackDate >= $startDate && $trackDate <= $endDate) {
                    $totalSeconds += (int)$seconds;
                }
            }

            $versions[$i]['editing_duration_seconds'] = $totalSeconds;
        }

        return $versions;
    }

    /**
     * Resolve a version's bytes on disk for download/preview. Uses the
     * version's own storage tier (falling back to the parent's for legacy
     * rows) and the version's own MIME type.
     */
    public function getVersionFilePath(string $email, int $fileId, int $versionId): ?array
    {
        $email = strtolower($email);

        $file = $this->drive->getFile($email, $fileId);
        if (!$file) return null;

        $version = $this->getVersionRow($fileId, $versionId);
        if (!$version) return null;

        $tierHint = $version['storage_location'] ?? $file['storage_location'] ?? null;
        $path = $this->drive->resolveFilePath($email, $version['filename'], $tierHint);
        if (!$path) return null;

        // Version downloads are still user reads of the same logical file.
        $this->drive->maybeTouchLastRead((int)$file['id']);

        return [
            'path' => $path,
            'filename' => $file['original_name'],
            'mime_type' => $version['mime_type'] ?: $file['mime_type'],
            'size' => $version['size'],
            'version_number' => $version['version_number'],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Restore / delete
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Restore an old version as the new current content (copy-on-restore,
     * the Google Drive semantic): the version's bytes are copied forward,
     * the outgoing current content is archived, and the restored version
     * row STAYS in history - restoring never deletes anything, so pins
     * and labels survive.
     *
     * For desktop NAS-registered files the restored bytes are written
     * over the file's visible NAS location (the user's real tree), which
     * the desktop client then syncs down like any remote change.
     *
     * @throws \RuntimeException when quota would be exceeded
     */
    public function restoreVersion(string $email, int $fileId, int $versionId): bool
    {
        $email = strtolower($email);

        $file = $this->drive->getFile($email, $fileId);
        if (!$file) return false;

        $version = $this->getVersionRow($fileId, $versionId);
        if (!$version) return false;

        $tierHint = $version['storage_location'] ?? $file['storage_location'] ?? null;
        $sourcePath = $this->drive->resolveFilePath($email, $version['filename'], $tierHint);
        if (!$sourcePath) {
            error_log("DriveVersioningService restoreVersion: bytes missing for version {$versionId}");
            return false;
        }

        $restoredSize = (int)$version['size'];
        if (!$this->drive->hasQuota($email, $restoredSize)) {
            throw new \RuntimeException('Not enough storage space to restore this version');
        }

        $isNasRegistered = ($file['storage_location'] ?? '') === 'nas' && !empty($file['nas_relative_path']);

        if ($isNasRegistered) {
            return $this->restoreOntoNasFile($email, $file, $version, $sourcePath);
        }

        $extension = pathinfo($version['filename'], PATHINFO_EXTENSION)
            ?: pathinfo($file['original_name'], PATHINFO_EXTENSION);
        $newFilename = bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');

        $userPath = $this->drive->getUserPath($email);
        $targetPath = $userPath . '/' . $newFilename;

        if (!copy($sourcePath, $targetPath)) {
            error_log("DriveVersioningService restoreVersion: copy failed {$sourcePath} -> {$targetPath}");
            return false;
        }

        $archive = $this->archiveCurrentAsVersion($email, $file);
        if (!$archive) {
            @unlink($targetPath);
            return false;
        }

        try {
            $this->db->prepare('
                UPDATE drive_files
                SET filename = ?, size = ?, mime_type = ?, checksum = ?, current_version = ?, last_modified_by = ?, storage_location = ?, updated_at = NOW()
                WHERE user_email = ? AND id = ?
            ')->execute([
                $newFilename,
                $restoredSize,
                $version['mime_type'] ?: $file['mime_type'],
                $version['checksum'] ?? null,
                $archive['version_number'] + 1,
                $email,
                $this->drive->resolveStorageLocation(),
                $email,
                $fileId,
            ]);
        } catch (\PDOException $e) {
            error_log('DriveVersioningService restoreVersion error: ' . $e->getMessage());
            @unlink($targetPath);
            return false;
        }

        $this->drive->enqueueNasMigrationIfNeeded($fileId, $email, $targetPath);
        $this->drive->updateUsedSpace($email, $restoredSize);

        $this->pruneFileVersions($email, $fileId);

        return true;
    }

    /**
     * Restore branch for desktop NAS-registered files: the current bytes
     * are archived (copied into the hashed store, quota charged inside
     * archiveCurrentAsVersion), then the version's bytes overwrite the
     * file's visible NAS location in place. filename/nas_relative_path
     * stay untouched so the user's NAS tree keeps its structure.
     */
    private function restoreOntoNasFile(string $email, array $file, array $version, string $sourcePath): bool
    {
        $fileId = (int)$file['id'];
        $livePath = $this->drive->getFilePath($email, $fileId);
        if (!$livePath) {
            error_log("DriveVersioningService restoreOntoNasFile: live path unresolvable for file {$fileId}");
            return false;
        }

        $archive = $this->archiveCurrentAsVersion($email, $file);
        if (!$archive) return false;

        if (!copy($sourcePath, $livePath)) {
            error_log("DriveVersioningService restoreOntoNasFile: copy failed {$sourcePath} -> {$livePath}");
            return false;
        }

        $restoredSize = (int)$version['size'];

        try {
            $this->db->prepare('
                UPDATE drive_files
                SET size = ?, mime_type = ?, checksum = ?, current_version = ?, last_modified_by = ?, updated_at = NOW()
                WHERE user_email = ? AND id = ?
            ')->execute([
                $restoredSize,
                $version['mime_type'] ?: $file['mime_type'],
                $version['checksum'] ?? null,
                $archive['version_number'] + 1,
                $email,
                $email,
                $fileId,
            ]);
        } catch (\PDOException $e) {
            error_log('DriveVersioningService restoreOntoNasFile error: ' . $e->getMessage());
            return false;
        }

        // Archive charged the old size; the live file changed by the delta.
        $this->drive->updateUsedSpace($email, $restoredSize - (int)$file['size']);

        $this->pruneFileVersions($email, $fileId);

        return true;
    }

    /**
     * Delete one version: physical bytes, quota refund, row. An explicit
     * delete is allowed even on pinned versions - the pin only protects
     * against AUTOMATIC pruning and bulk cleanup.
     */
    public function deleteVersion(string $email, int $fileId, int $versionId): bool
    {
        $email = strtolower($email);

        $file = $this->drive->getFile($email, $fileId);
        if (!$file) return false;

        $version = $this->getVersionRow($fileId, $versionId);
        if (!$version) return false;

        return $this->deleteVersionRowAndFile($email, $version, $file);
    }

    /**
     * Remove every version of a file (called when the file itself is
     * permanently deleted).
     */
    public function deleteAllVersions(string $email, int $fileId): void
    {
        $email = strtolower($email);

        $stmt = $this->db->prepare('SELECT * FROM drive_file_versions WHERE file_id = ?');
        $stmt->execute([$fileId]);

        foreach ($stmt->fetchAll() as $version) {
            $this->deleteVersionRowAndFile($email, $version, null);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Pin / label
    // ─────────────────────────────────────────────────────────────────────

    public function setVersionPinned(string $email, int $fileId, int $versionId, bool $pinned): bool
    {
        if (!$this->drive->getFile(strtolower($email), $fileId)) return false;

        $stmt = $this->db->prepare('UPDATE drive_file_versions SET is_pinned = ? WHERE id = ? AND file_id = ?');
        $stmt->execute([$pinned ? 1 : 0, $versionId, $fileId]);
        return $stmt->rowCount() > 0 || $this->getVersionRow($fileId, $versionId) !== null;
    }

    public function setVersionLabel(string $email, int $fileId, int $versionId, ?string $label): bool
    {
        if (!$this->drive->getFile(strtolower($email), $fileId)) return false;

        $label = $label !== null ? mb_substr(trim($label), 0, 255) : null;
        if ($label === '') $label = null;

        $stmt = $this->db->prepare('UPDATE drive_file_versions SET label = ? WHERE id = ? AND file_id = ?');
        $stmt->execute([$label, $versionId, $fileId]);
        return $stmt->rowCount() > 0 || $this->getVersionRow($fileId, $versionId) !== null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Usage / cleanup
    // ─────────────────────────────────────────────────────────────────────

    /**
     * How much of the user's quota is consumed by version history, plus
     * the heaviest files so the UI can offer targeted cleanup.
     */
    public function getVersionsUsage(string $email): array
    {
        $email = strtolower($email);

        $stmt = $this->db->prepare('
            SELECT COUNT(v.id) AS version_count,
                   COALESCE(SUM(v.size), 0) AS version_bytes,
                   COALESCE(SUM(v.is_pinned), 0) AS pinned_count
            FROM drive_file_versions v
            JOIN drive_files f ON f.id = v.file_id
            WHERE f.user_email = ?
        ');
        $stmt->execute([$email]);
        $totals = $stmt->fetch() ?: ['version_count' => 0, 'version_bytes' => 0, 'pinned_count' => 0];

        $stmt = $this->db->prepare('
            SELECT f.id AS file_id, f.original_name,
                   COUNT(v.id) AS version_count,
                   COALESCE(SUM(v.size), 0) AS version_bytes
            FROM drive_file_versions v
            JOIN drive_files f ON f.id = v.file_id
            WHERE f.user_email = ?
            GROUP BY f.id, f.original_name
            ORDER BY version_bytes DESC
            LIMIT 10
        ');
        $stmt->execute([$email]);

        return [
            'version_count' => (int)$totals['version_count'],
            'version_bytes' => (int)$totals['version_bytes'],
            'pinned_count' => (int)$totals['pinned_count'],
            'top_files' => $stmt->fetchAll(),
        ];
    }

    /**
     * One-click cleanup for a single file: delete every UNPINNED version.
     * Returns ['deleted' => n, 'freed_bytes' => b].
     */
    public function cleanupFileVersions(string $email, int $fileId): array
    {
        $email = strtolower($email);

        $file = $this->drive->getFile($email, $fileId);
        if (!$file) return ['deleted' => 0, 'freed_bytes' => 0];

        $stmt = $this->db->prepare('SELECT * FROM drive_file_versions WHERE file_id = ? AND is_pinned = 0');
        $stmt->execute([$fileId]);

        return $this->deleteVersionBatch($email, $stmt->fetchAll(), $file);
    }

    /**
     * Account-wide cleanup: delete every unpinned version of every file.
     */
    public function cleanupAllVersions(string $email): array
    {
        $email = strtolower($email);

        $stmt = $this->db->prepare('
            SELECT v.* FROM drive_file_versions v
            JOIN drive_files f ON f.id = v.file_id
            WHERE f.user_email = ? AND v.is_pinned = 0
        ');
        $stmt->execute([$email]);

        return $this->deleteVersionBatch($email, $stmt->fetchAll(), null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Retention engine (smart thinning)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Apply the retention policy to one file. Runs opportunistically after
     * each version creation and in bulk from the prune cron.
     *
     * Policy (pinned versions are always exempt):
     *   - keep ALL versions newer than keep_all_hours (default 24h)
     *   - older, within daily_window_days (default 30d): newest per day
     *   - beyond that: newest per ISO week
     *   - hard cap max_versions_per_file (default 50): oldest unpinned
     *     versions beyond the cap are pruned
     */
    public function pruneFileVersions(string $email, int $fileId, ?int $nowTs = null): array
    {
        $email = strtolower($email);

        $stmt = $this->db->prepare('SELECT * FROM drive_file_versions WHERE file_id = ?');
        $stmt->execute([$fileId]);
        $versions = $stmt->fetchAll();

        $toDelete = $this->selectPrunableVersions($versions, $nowTs ?? time());
        if (!$toDelete) {
            return ['deleted' => 0, 'freed_bytes' => 0];
        }

        return $this->deleteVersionBatch($email, $toDelete, null);
    }

    /**
     * Pure thinning algorithm - decides WHICH versions to prune without
     * touching disk or DB (kept side-effect free so the test script can
     * exercise it against synthetic timelines).
     *
     * @param array $versions rows with id, version_number, size, is_pinned, created_at
     * @return array subset of $versions to delete
     */
    public function selectPrunableVersions(array $versions, int $nowTs): array
    {
        $pinned = [];
        $candidates = [];
        foreach ($versions as $v) {
            if (!empty($v['is_pinned'])) {
                $pinned[] = $v;
            } else {
                $candidates[] = $v;
            }
        }

        // Newest first; ties broken by version_number so ordering is stable.
        usort($candidates, function ($a, $b) {
            $cmp = strtotime((string)$b['created_at']) <=> strtotime((string)$a['created_at']);
            return $cmp !== 0 ? $cmp : ((int)$b['version_number'] <=> (int)$a['version_number']);
        });

        $keepAllCutoff = $nowTs - $this->keepAllHours * 3600;
        $dailyCutoff   = $nowTs - $this->dailyWindowDays * 86400;

        $keep = [];
        $delete = [];
        $seenBuckets = [];

        foreach ($candidates as $v) {
            $ts = strtotime((string)$v['created_at']) ?: 0;

            if ($ts >= $keepAllCutoff) {
                $keep[] = $v;
                continue;
            }

            // One survivor per calendar day (recent) or ISO week (old);
            // newest-first iteration means the first hit in a bucket is
            // the newest of that bucket.
            $bucket = $ts >= $dailyCutoff
                ? 'd' . date('Y-m-d', $ts)
                : 'w' . date('o-W', $ts);

            if (isset($seenBuckets[$bucket])) {
                $delete[] = $v;
            } else {
                $seenBuckets[$bucket] = true;
                $keep[] = $v;
            }
        }

        // Hard cap counts everything that survives (pinned included), but
        // only unpinned survivors can be evicted - oldest first.
        $overflow = (count($keep) + count($pinned)) - $this->maxVersions;
        if ($overflow > 0) {
            // $keep is newest-first, so evict from the tail.
            $evicted = array_splice($keep, -$overflow);
            $delete = array_merge($delete, $evicted);
        }

        return $delete;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Internals
    // ─────────────────────────────────────────────────────────────────────

    private function getVersionRow(int $fileId, int $versionId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM drive_file_versions WHERE id = ? AND file_id = ?');
        $stmt->execute([$versionId, $fileId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Delete a version's physical file (tier-aware), refund its quota and
     * remove its row. The quota refund always happens even when the bytes
     * are already gone - the ledger tracks rows, not disk reality.
     */
    private function deleteVersionRowAndFile(string $email, array $version, ?array $file): bool
    {
        $tierHint = $version['storage_location'] ?? ($file['storage_location'] ?? null);
        $path = $this->drive->resolveFilePath($email, $version['filename'], $tierHint);
        if ($path && is_file($path)) {
            @unlink($path);
        }

        $this->drive->updateUsedSpace($email, -(int)$version['size']);

        // Orphan any pending NAS-migration row that pointed at this version.
        try {
            $this->db->prepare("
                UPDATE drive_pending_nas_migration
                SET status = 'completed', error_message = 'version deleted before migration'
                WHERE version_id = ? AND status IN ('pending', 'migrating')
            ")->execute([(int)$version['id']]);
        } catch (\Throwable $e) {
            // Column may not exist yet on un-migrated installs - non-fatal.
        }

        $stmt = $this->db->prepare('DELETE FROM drive_file_versions WHERE id = ?');
        $stmt->execute([(int)$version['id']]);

        return $stmt->rowCount() > 0;
    }

    private function deleteVersionBatch(string $email, array $versions, ?array $file): array
    {
        $deleted = 0;
        $freed = 0;
        foreach ($versions as $version) {
            if ($this->deleteVersionRowAndFile($email, $version, $file)) {
                $deleted++;
                $freed += (int)$version['size'];
            }
        }
        return ['deleted' => $deleted, 'freed_bytes' => $freed];
    }

    /**
     * Re-point a queued local->NAS migration at the version row that now
     * owns the bytes (matched by the stored filename at the tail of
     * local_path). Swallows errors: worst case the worker stamps the
     * parent file's tier, which resolveFilePath()'s fallback tolerates.
     */
    private function repointPendingNasMigration(int $fileId, string $filename, int $versionRowId): void
    {
        try {
            $this->db->prepare("
                UPDATE drive_pending_nas_migration
                SET version_id = ?
                WHERE file_id = ? AND version_id IS NULL
                  AND status IN ('pending', 'migrating')
                  AND SUBSTRING_INDEX(local_path, '/', -1) = ?
            ")->execute([$versionRowId, $fileId, $filename]);
        } catch (\Throwable $e) {
            // Column missing pre-migration-190 - non-fatal.
        }
    }
}
