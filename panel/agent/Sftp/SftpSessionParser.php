<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Sftp;

/**
 * Pure classifier for sshd / internal-sftp journal lines.
 *
 * Turns a single log MESSAGE (plus its PID + timestamp) into ONE typed
 * session event, or null when the line is irrelevant. It holds no state and
 * does no I/O, so it is exhaustively unit-testable from fixed sample lines;
 * all correlation + persistence lives in SftpSessionIngestor / SftpSessionStore.
 *
 * Recognised events:
 *   - login   : "Accepted <method> for <user> from <ip> port <n> ssh2"
 *   - logout  : "Disconnected from user <user> <ip> port <n>"
 *               "pam_unix(sshd:session): session closed for user <user>"
 *   - xfer    : "close \"<path>\" bytes read <R> written <W>"   (internal-sftp -l INFO)
 *
 * Byte direction is taken straight from OpenSSH's wording (server's view):
 *   read  = bytes the server read out of the file  -> client downloaded
 *   write = bytes the server wrote into the file    -> client uploaded
 */
final class SftpSessionParser
{
    /**
     * Classify one journal record.
     *
     * @param string $message The raw MESSAGE field.
     * @param int    $pid      The originating _PID (connection/session pid).
     * @param float  $ts       Event time as a unix timestamp (float seconds).
     *
     * @return array{type:string,pid:int,ts:float,user:string,ip?:string,read?:int,written?:int}|null
     */
    public function classify(string $message, int $pid, float $ts): ?array
    {
        $message = trim($message);
        if ($message === '' || $pid <= 0) {
            return null;
        }

        // ── login ───────────────────────────────────────────────
        if (preg_match(
            '/^Accepted (?:password|publickey|keyboard-interactive(?:\/\w+)?) for (\S+) from (\S+) port (\d+)/',
            $message,
            $m
        ) === 1) {
            return [
                'type' => 'login',
                'pid'  => $pid,
                'ts'   => $ts,
                'user' => $m[1],
                'ip'   => $m[2],
            ];
        }

        // ── logout (two spellings; either closes the session) ────
        if (preg_match('/^Disconnected from user (\S+) (\S+) port (\d+)/', $message, $m) === 1) {
            return ['type' => 'logout', 'pid' => $pid, 'ts' => $ts, 'user' => $m[1], 'ip' => $m[2]];
        }
        if (preg_match('/session closed for user (\S+)/', $message, $m) === 1) {
            return ['type' => 'logout', 'pid' => $pid, 'ts' => $ts, 'user' => $m[1]];
        }

        // ── transfer (internal-sftp close record) ────────────────
        // e.g.  close "/uploads/big.zip" bytes read 0 written 10485760
        if (preg_match('/\bclose "(?:[^"]*)" bytes read (\d+) written (\d+)/', $message, $m) === 1) {
            return [
                'type'    => 'xfer',
                'pid'     => $pid,
                'ts'      => $ts,
                'user'    => '', // sftp close lines carry no username; resolved by pid
                'read'    => (int) $m[1],
                'written' => (int) $m[2],
            ];
        }

        return null;
    }

    /**
     * Build the stable session key for a login event. PID alone is not
     * unique over time, so we bind it to the login second.
     */
    public static function sessionKey(string $user, int $pid, float $loginTs): string
    {
        return $user . ':' . $pid . ':' . (int) $loginTs;
    }
}
