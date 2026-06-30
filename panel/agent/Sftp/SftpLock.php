<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Sftp;

/**
 * Single advisory lock that serializes every mutation of shared system
 * state owned by the SFTP-user feature.
 *
 * Why this exists:
 *   - Two admins creating/deleting SFTP users at the same time would
 *     otherwise race on /etc/fstab, /etc/ssh/flowone-sftp-keys/, the
 *     managed sshd drop-in, and the shared `flowone_sftp` group. A
 *     half-applied read-modify-write on any of those can corrupt the
 *     file or, worse, produce an sshd config that fails `sshd -t`.
 *   - One coarse lock is more than enough: these operations are rare
 *     (operator-driven), short, and must be globally consistent.
 *
 * The lock is an exclusive flock() on /run/flowone-sftp.lock. flock is
 * advisory and released automatically if the process dies, so a crashed
 * worker can never wedge the feature permanently.
 */
final class SftpLock
{
    private const LOCK_PATH = '/run/flowone-sftp.lock';

    /**
     * Run $fn while holding the exclusive lock. The lock is always
     * released, even if $fn throws.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function run(callable $fn, int $timeoutSeconds = 30)
    {
        $fp = @fopen(self::LOCK_PATH, 'c');
        if ($fp === false) {
            throw new \RuntimeException('Could not open SFTP lock file: ' . self::LOCK_PATH);
        }

        $deadline = microtime(true) + max(1, $timeoutSeconds);
        $acquired = false;
        while (microtime(true) < $deadline) {
            if (flock($fp, LOCK_EX | LOCK_NB)) {
                $acquired = true;
                break;
            }
            usleep(100_000);
        }

        if (!$acquired) {
            fclose($fp);
            throw new \RuntimeException(
                'Timed out acquiring SFTP lock after ' . $timeoutSeconds . 's - another operation is in progress'
            );
        }

        try {
            return $fn();
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }
}
