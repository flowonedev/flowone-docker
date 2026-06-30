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
 * Tear down every mail artifact for a site being hard-DELETEd.
 *
 * Added after a production incident: deleted sites kept appearing on
 * the Mail Security page because nothing in the delete saga removed
 * the mail_domains row, and DKIM keys / vmail dirs accumulated as
 * orphans (test.com / testsite.hu leftovers, June 2026).
 *
 * Inverse of MailAction::actionAddDomain() + generateDkimKeys(),
 * which create:
 *
 *   1. mail_domains row                  (panel DB)
 *   2. mail_accounts / mail_forwards     (panel DB, per-account)
 *   3. /home/vmail/<domain>/             (maildir tree)
 *   4. /etc/opendkim/keys/<domain>/      (DKIM keypair)
 *   5. SigningTable / KeyTable lines     (/etc/opendkim/*)
 *
 * The mail-DNS records (MX / SPF / DMARC / DKIM TXT) live inside the
 * site's zone and are dropped wholesale by DnsZoneRemoveStep, so this
 * step does not touch DNS.
 *
 * Recovery artifact:
 *   The PRE_DELETE_SNAPSHOT home tarball does NOT include
 *   /home/vmail/<domain>. To keep the saga's "delete always leaves a
 *   recovery artifact" invariant, a non-empty maildir is tar'd into
 *   the same snapshot dir (<snapshot_root>/<domain>/<jobId>/vmail.tar.gz)
 *   before removal. An empty maildir is removed without a tarball.
 *   Operators can skip the tarball with payload['skip_mail_snapshot'].
 *
 * Idempotence:
 *   - check() returns true iff no artifact (DB row, maildir, DKIM
 *     key dir, DKIM table line) exists for the domain.
 *   - execute() handles each artifact independently and tolerates
 *     any subset being already gone, so a resume re-run converges.
 *
 * Compensation: DEGRADE_ONLY.
 *   Recreating DKIM keys would change the public key (DNS mismatch)
 *   and mailbox bytes cannot be un-deleted. Park degraded; the
 *   operator restores from the vmail tarball if needed.
 *
 * NOT in the ARCHIVE saga: mailboxes aren't captured by the archive
 * snapshot and RESTORE has no mail re-create step, so archiving a
 * site intentionally leaves mail running.
 */
final class MailTeardownStep extends AbstractStep
{
    /**
     * Production roots. Tests (and exotic installs) override via
     * payload['vmail_root'] / payload['dkim_root'] - same pattern as
     * PreDeleteSnapshotStep's payload['snapshot_root'].
     */
    private const DEFAULT_VMAIL_ROOT = '/home/vmail';
    private const DEFAULT_DKIM_ROOT = '/etc/opendkim';
    private const DEFAULT_SNAPSHOT_ROOT = '/var/www/vps-admin/storage/snapshots';

    public function name(): string
    {
        return StepName::MAIL_TEARDOWN;
    }

    public function compensationPolicy(): CompensationPolicy
    {
        return CompensationPolicy::DEGRADE_ONLY;
    }

    public function check(SiteContext $ctx, StepState $state): bool
    {
        $domain = $ctx->domain();
        if ($domain === '') {
            return true;
        }

        try {
            $stmt = $ctx->database->pdo()->prepare(
                "SELECT id FROM mail_domains WHERE domain = ? LIMIT 1"
            );
            $stmt->execute([$domain]);
            if ($stmt->fetchColumn() !== false) {
                return false;
            }
        } catch (\Throwable) {
            // mail tables absent on this install - nothing to tear down
            // in the DB; fall through to the filesystem checks.
        }

        if (is_dir($this->vmailRoot($ctx) . '/' . $domain)) {
            return false;
        }
        if (is_dir($this->dkimRoot($ctx) . '/keys/' . $domain)) {
            return false;
        }
        if ($this->dkimTablesMention($ctx, $domain)) {
            return false;
        }
        return true;
    }

    public function execute(SiteContext $ctx, StepState $state): StepResult
    {
        $domain = $ctx->domain();
        $pdo = $ctx->database->pdo();
        $fs = $ctx->requireAdapters()->fs;
        $runner = $ctx->requireAdapters()->runner;
        $events = [];
        $removed = [];

        // ── 1) vmail maildir (snapshot first, then remove) ─────
        $vmailRoot = $this->vmailRoot($ctx);
        $vmailDir = $vmailRoot . '/' . $domain;
        if (is_dir($vmailDir)) {
            if (($ctx->payload['skip_mail_snapshot'] ?? false) !== true
                && !$this->dirIsEmpty($vmailDir)
            ) {
                $tarPath = $this->snapshotDir($ctx) . '/vmail.tar.gz';
                try {
                    $fs->ensureDirectory(dirname($tarPath), 0700);
                } catch (\Throwable $e) {
                    return StepResult::failure(
                        $state,
                        "could not create snapshot dir for vmail tarball: " . $e->getMessage(),
                        $events,
                    );
                }
                $tar = $runner->run(
                    'tar',
                    ['-czf', $tarPath, '-C', $vmailRoot, $domain],
                    null,
                    600,
                );
                if (!$tar->isSuccess()) {
                    // Mailbox bytes exist and we could NOT secure them.
                    // Refuse to destroy data without a recovery artifact.
                    return StepResult::failure(
                        $state,
                        "vmail snapshot tar failed; refusing to delete maildir without artifact: "
                            . $tar->summary(),
                        $events,
                    );
                }
                $events[] = StepEvent::info('vmail maildir snapshotted', [
                    'tar' => $tarPath,
                ]);
                $state = $state->mergeData(['vmail_tar' => $tarPath]);
            }

            try {
                $entries = $fs->rmtree($vmailDir);
                $removed['vmail_dir'] = true;
                $events[] = StepEvent::info('vmail maildir removed', [
                    'dir' => $vmailDir, 'entries' => $entries,
                ]);
            } catch (\Throwable $e) {
                return StepResult::failure(
                    $state,
                    "rmtree({$vmailDir}) failed: " . $e->getMessage(),
                    $events,
                );
            }
        }

        // ── 2) panel DB rows (accounts/forwards first, then domain) ──
        // Each table independently: the mail tables may not exist on a
        // panel without the mail stack, and a missing table must not
        // fail the delete saga. Real rows that survive are caught by
        // the post-delete leftover scan.
        $dbRows = [];
        foreach ([
            'mail_accounts' => "DELETE FROM mail_accounts WHERE domain = ?",
            'mail_forwards' => "DELETE FROM mail_forwards WHERE source_domain = ?",
            'mail_domains' => "DELETE FROM mail_domains WHERE domain = ?",
        ] as $table => $sql) {
            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$domain]);
                $dbRows[$table] = $stmt->rowCount();
            } catch (\Throwable $e) {
                $events[] = StepEvent::warning("{$table} cleanup skipped (table absent?)", [
                    'detail' => $e->getMessage(),
                ]);
            }
        }
        if ($dbRows !== []) {
            $removed['db_rows'] = $dbRows;
            if (array_sum($dbRows) > 0) {
                $events[] = StepEvent::info('mail DB rows removed', $dbRows);
            }
        }

        // ── 3) opendkim key dir ────────────────────────────────
        $dkimRoot = $this->dkimRoot($ctx);
        $dkimDir = $dkimRoot . '/keys/' . $domain;
        if (is_dir($dkimDir)) {
            try {
                $fs->rmtree($dkimDir);
                $removed['dkim_keys'] = true;
                $events[] = StepEvent::info('opendkim key dir removed', ['dir' => $dkimDir]);
            } catch (\Throwable $e) {
                return StepResult::failure(
                    $state,
                    "rmtree({$dkimDir}) failed: " . $e->getMessage(),
                    $events,
                );
            }
        }

        // ── 4) SigningTable / KeyTable lines ───────────────────
        $tablesChanged = false;
        foreach ([$dkimRoot . '/SigningTable', $dkimRoot . '/KeyTable'] as $table) {
            $changed = $this->stripDomainLines($fs, $table, $domain);
            if ($changed !== null) {
                $tablesChanged = $tablesChanged || $changed > 0;
                if ($changed > 0) {
                    $events[] = StepEvent::info('dkim table entries removed', [
                        'table' => $table, 'lines' => $changed,
                    ]);
                }
            }
        }

        // ── 5) reload opendkim (best-effort; tables changed) ───
        if ($tablesChanged || isset($removed['dkim_keys'])) {
            try {
                $reload = $runner->run('systemctl', ['reload-or-restart', 'opendkim'], null, 30);
                if ($reload->isSuccess()) {
                    $events[] = StepEvent::info('opendkim reloaded');
                } else {
                    // Non-fatal: tables are already consistent on disk; the
                    // next opendkim restart picks them up.
                    $events[] = StepEvent::warning('opendkim reload failed (non-fatal)', [
                        'detail' => $reload->summary(),
                    ]);
                }
            } catch (\Throwable $e) {
                $events[] = StepEvent::warning('opendkim reload unavailable (non-fatal)', [
                    'detail' => $e->getMessage(),
                ]);
            }
        }

        return StepResult::success(
            $state->mergeData(['removed' => $removed])->withCompleted(),
            $events !== [] ? $events : [StepEvent::info('no mail artifacts found, no-op', [
                'domain' => $domain,
            ])],
            ['removed' => count($removed)],
        );
    }

    public function compensate(SiteContext $ctx, StepState $state): StepResult
    {
        return StepResult::success(
            $state,
            [StepEvent::warning(
                'compensate: mail artifacts NOT recreated (DEGRADE_ONLY); '
                    . 'restore maildir from vmail tarball if needed',
                [
                    'domain' => $ctx->domain(),
                    'vmail_tar' => $state->data['vmail_tar'] ?? null,
                ]
            )]
        );
    }

    /**
     * Remove every line mentioning the domain from a DKIM table file.
     *
     * Matching is deliberately precise: SigningTable lines look like
     * `*@<domain> <selector>._domainkey.<domain>` and KeyTable lines
     * like `<selector>._domainkey.<domain> <domain>:<selector>:<path>`.
     * A word-boundary match on the dot-escaped domain avoids nuking
     * `notmy<domain>` while catching every legitimate form.
     *
     * @return int|null Lines removed, or null when the file is absent.
     */
    private function stripDomainLines(
        \VpsAdmin\Agent\Provisioner\Adapters\FilesystemAdapter $fs,
        string $path,
        string $domain
    ): ?int {
        $content = $fs->readFile($path);
        if ($content === null) {
            return null;
        }

        $pattern = '/(^|[@.\s])' . preg_quote($domain, '/') . '([\s:]|$)/';
        $kept = [];
        $dropped = 0;
        foreach (explode("\n", $content) as $line) {
            if ($line !== '' && preg_match($pattern, $line) === 1) {
                $dropped++;
                continue;
            }
            $kept[] = $line;
        }

        if ($dropped > 0) {
            $fs->writeAtomic($path, implode("\n", $kept), 0644);
        }
        return $dropped;
    }

    private function dkimTablesMention(SiteContext $ctx, string $domain): bool
    {
        $pattern = '/(^|[@.\s])' . preg_quote($domain, '/') . '([\s:]|$)/m';
        $dkimRoot = $this->dkimRoot($ctx);
        foreach ([$dkimRoot . '/SigningTable', $dkimRoot . '/KeyTable'] as $table) {
            if (!is_file($table)) {
                continue;
            }
            $content = @file_get_contents($table);
            if ($content !== false && preg_match($pattern, $content) === 1) {
                return true;
            }
        }
        return false;
    }

    private function vmailRoot(SiteContext $ctx): string
    {
        $root = $ctx->payload['vmail_root'] ?? self::DEFAULT_VMAIL_ROOT;
        if (!is_string($root) || $root === '' || $root[0] !== '/') {
            $root = self::DEFAULT_VMAIL_ROOT;
        }
        return rtrim($root, '/');
    }

    private function dkimRoot(SiteContext $ctx): string
    {
        $root = $ctx->payload['dkim_root'] ?? self::DEFAULT_DKIM_ROOT;
        if (!is_string($root) || $root === '' || $root[0] !== '/') {
            $root = self::DEFAULT_DKIM_ROOT;
        }
        return rtrim($root, '/');
    }

    private function dirIsEmpty(string $dir): bool
    {
        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }
        return count($entries) <= 2;
    }

    private function snapshotDir(SiteContext $ctx): string
    {
        $root = $ctx->payload['snapshot_root'] ?? self::DEFAULT_SNAPSHOT_ROOT;
        if (!is_string($root) || $root === '' || $root[0] !== '/') {
            $root = self::DEFAULT_SNAPSHOT_ROOT;
        }
        return rtrim($root, '/') . '/' . $ctx->domain() . '/' . $ctx->jobId;
    }
}
