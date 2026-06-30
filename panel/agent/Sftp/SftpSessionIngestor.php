<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Sftp;

/**
 * Drains new sshd / internal-sftp journal entries into sftp_sessions.
 *
 * Flow: read the systemd journal since our stored cursor -> classify each
 * line (SftpSessionParser) -> apply the typed events against the DB
 * (SftpSessionStore), correlating transfer + logout lines back to the open
 * session by connection PID. Idempotent and incremental: the cursor only
 * moves forward and every write keys on session_key / PID.
 *
 * The journal reader is injectable so the apply pipeline can be unit-tested
 * with fixed sample lines and no real journald.
 */
final class SftpSessionIngestor
{
    /** First run (no cursor) only backfills this far, to bound the scan. */
    private const FIRST_RUN_WINDOW = '-1 hour';
    /** Hard cap on entries per run so a log storm can't run us out of memory. */
    private const MAX_ENTRIES = 100000;

    /** @var callable(?string):array{0:list<array{ts:float,pid:int,message:string}>,1:?string} */
    private $reader;

    public function __construct(
        private readonly SftpSessionStore $store,
        private readonly SftpSessionParser $parser,
        ?callable $reader = null
    ) {
        $this->reader = $reader ?? fn(?string $cursor): array => $this->readJournal($cursor);
    }

    /**
     * @return array<string,int|string> run summary
     */
    public function run(int $retentionDays = 90): array
    {
        $this->store->ensureSchema();
        $known = $this->store->knownUsers();

        $cursor = $this->store->getCursor();
        [$entries, $newCursor] = ($this->reader)($cursor);

        $applied = $this->apply($entries, $known);

        if (is_string($newCursor) && $newCursor !== '') {
            $this->store->setCursor($newCursor);
        }

        $pruned = $this->store->pruneOlderThan($retentionDays);
        $staleClosed = $this->store->closeStaleOpen(48);

        return [
            'read' => count($entries),
            'logins' => $applied['logins'],
            'logouts' => $applied['logouts'],
            'transfers' => $applied['transfers'],
            'skipped' => $applied['skipped'],
            'pruned' => $pruned,
            'stale_closed' => $staleClosed,
            'cursor_advanced' => (is_string($newCursor) && $newCursor !== '') ? 1 : 0,
        ];
    }

    /**
     * Apply a chronological batch of journal entries.
     *
     * @param list<array{ts:float,pid:int,message:string}>      $entries
     * @param array<string,array{id:int,domain:?string}>        $known
     * @return array{logins:int,logouts:int,transfers:int,skipped:int}
     */
    public function apply(array $entries, array $known): array
    {
        usort($entries, static fn($a, $b) => $a['ts'] <=> $b['ts']);

        $stats = ['logins' => 0, 'logouts' => 0, 'transfers' => 0, 'skipped' => 0];

        foreach ($entries as $e) {
            $event = $this->parser->classify(
                (string) ($e['message'] ?? ''),
                (int) ($e['pid'] ?? 0),
                (float) ($e['ts'] ?? 0)
            );
            if ($event === null) {
                continue;
            }

            switch ($event['type']) {
                case 'login':
                    // Only track the additional SFTP users we manage.
                    if (!isset($known[$event['user']])) {
                        $stats['skipped']++;
                        break;
                    }
                    $u = $known[$event['user']];
                    $key = SftpSessionParser::sessionKey($event['user'], $event['pid'], $event['ts']);
                    $this->store->openSession(
                        $key,
                        $event['user'],
                        $u['id'],
                        $u['domain'],
                        $event['ip'] ?? null,
                        $event['pid'],
                        $event['ts']
                    );
                    $this->store->touchAggregate($u['id'], $event['ip'] ?? null, $event['ts']);
                    $stats['logins']++;
                    break;

                case 'xfer':
                    $id = $this->store->findOpenIdByPid($event['pid']);
                    if ($id === null) {
                        $stats['skipped']++;
                        break;
                    }
                    $this->store->addBytes($id, (int) ($event['read'] ?? 0), (int) ($event['written'] ?? 0));
                    $stats['transfers']++;
                    break;

                case 'logout':
                    $id = $this->store->findOpenIdByPid($event['pid']);
                    if ($id === null) {
                        $stats['skipped']++;
                        break;
                    }
                    $this->store->closeSession($id, $event['ts']);
                    $stats['logouts']++;
                    break;
            }
        }

        return $stats;
    }

    /**
     * Read new journal entries via journalctl JSON.
     *
     * @return array{0:list<array{ts:float,pid:int,message:string}>,1:?string}
     */
    private function readJournal(?string $cursor): array
    {
        // Match by PROCESS NAME, not by systemd unit. On socket-activated
        // sshd (default on recent Ubuntu/Debian) each connection runs as a
        // transient `ssh@…` unit, so `-u ssh` misses every session; the
        // per-connection process is `sshd` (classic) or `sshd-session`
        // (OpenSSH >= 9.6 privsep split), which also emits the internal-sftp
        // "close … bytes read/written" lines. journalctl ORs repeated
        // same-field matches, so this covers both layouts.
        $args = [
            'journalctl',
            '_COMM=sshd',
            '_COMM=sshd-session',
            '--output=json', '--no-pager', '-n', (string) self::MAX_ENTRIES,
        ];
        if ($cursor !== null && $cursor !== '') {
            $args[] = '--after-cursor=' . $cursor;
        } else {
            $args[] = '--since';
            $args[] = self::FIRST_RUN_WINDOW;
        }

        $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
        $out = [];
        $rc = 0;
        @exec($cmd, $out, $rc);

        $entries = [];
        $lastCursor = null;
        foreach ($out as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $message = $row['MESSAGE'] ?? '';
            if (is_array($message)) {
                // journald encodes non-UTF8 messages as a byte array.
                $message = implode('', array_map(static fn($b) => chr((int) $b), $message));
            }
            $entries[] = [
                'ts' => isset($row['__REALTIME_TIMESTAMP']) ? ((float) $row['__REALTIME_TIMESTAMP']) / 1e6 : 0.0,
                'pid' => (int) ($row['_PID'] ?? 0),
                'message' => (string) $message,
            ];
            if (isset($row['__CURSOR']) && is_string($row['__CURSOR'])) {
                $lastCursor = $row['__CURSOR'];
            }
        }

        return [$entries, $lastCursor];
    }
}
