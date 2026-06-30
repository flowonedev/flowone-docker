<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Sftp;

use VpsAdmin\Agent\Provisioner\Adapters\CommandRunner;
use VpsAdmin\Agent\Provisioner\Adapters\FilesystemAdapter;
use VpsAdmin\Agent\Provisioner\Adapters\ProcessCommandRunner;

/**
 * Owns the ONE static sshd snippet that powers every additional SFTP
 * user. Because the per-user difference (which folder, which jail) is
 * carried by the user's home directory and group membership, sshd only
 * ever needs this single `Match Group flowone_sftp` block - written
 * once, validated, and reloaded. Creating the 2nd..Nth user never
 * touches sshd again.
 *
 * Safety: we ALWAYS `sshd -t` before reloading and roll the drop-in
 * back on failure, because a bad sshd_config that gets reloaded can
 * lock the operator out of the box. We `reload` (not `restart`) so live
 * sessions survive (#13).
 *
 * Assumes the caller holds SftpLock for any mutating call.
 */
final class SshdSftpConfigurator
{
    private const DROPIN_DIR = '/etc/ssh/sshd_config.d';
    private const DROPIN = '/etc/ssh/sshd_config.d/flowone-sftp.conf';
    private const MAIN_CONFIG = '/etc/ssh/sshd_config';
    private const SSHD_BIN = '/usr/sbin/sshd';

    private CommandRunner $runner;
    private FilesystemAdapter $fs;

    public function __construct(
        ?CommandRunner $runner = null,
        private readonly string $dropinPath = self::DROPIN,
        private readonly string $mainConfig = self::MAIN_CONFIG
    ) {
        $this->runner = $runner ?? new ProcessCommandRunner();
        $this->fs = new FilesystemAdapter($this->runner, ['/etc/ssh']);
    }

    public function desiredConfig(): string
    {
        return <<<CONF
# Managed by FlowOne - additional restricted SFTP users. Do not edit by hand.
# Each user in the flowone_sftp group is chrooted to their own root-owned
# home (the jail), with one folder bind-mounted in. internal-sftp keeps the
# session in-process so no shell is needed inside the jail.
# `-l INFO` makes internal-sftp log each file operation (incl. a per-file
# "close ... bytes read N written M" line) so the panel can attribute
# transfer volume to a session; FlowOne's session sync parses these.
Match Group flowone_sftp
    ChrootDirectory %h
    ForceCommand internal-sftp -u 0002 -l INFO
    AllowTcpForwarding no
    X11Forwarding no
    AuthorizedKeysFile /etc/ssh/flowone-sftp-keys/%u
    PasswordAuthentication yes

CONF;
    }

    public function isConfigured(): bool
    {
        $current = $this->fs->readFile($this->dropinPath);
        return $current !== null && trim($current) === trim($this->desiredConfig());
    }

    /**
     * Ensure the drop-in is present and correct, the main config
     * includes the drop-in dir, the key dir exists, then validate +
     * reload. Idempotent. Returns true if a change was applied.
     */
    public function ensureConfigured(): bool
    {
        $this->fs->ensureDirectory(self::DROPIN_DIR, 0755);
        $includeChanged = $this->ensureInclude();

        if ($this->isConfigured() && !$includeChanged) {
            return false;
        }

        $previous = $this->fs->readFile($this->dropinPath);
        $this->fs->writeAtomic($this->dropinPath, $this->desiredConfig(), 0644);

        $test = $this->configTest();
        if (!$test['ok']) {
            // Roll back to the prior state and re-validate so we never
            // leave a broken config staged.
            if ($previous === null) {
                $this->fs->deleteFile($this->dropinPath);
            } else {
                $this->fs->writeAtomic($this->dropinPath, $previous, 0644);
            }
            throw new \RuntimeException('sshd config test failed, rolled back: ' . $test['output']);
        }

        $this->reload();
        return true;
    }

    /**
     * @return array{ok:bool, output:string}
     */
    public function configTest(): array
    {
        $r = $this->runner->run(self::SSHD_BIN, ['-t'], null, 15);
        return ['ok' => $r->isSuccess(), 'output' => trim($r->stderr . "\n" . $r->stdout)];
    }

    public function reload(): void
    {
        $test = $this->configTest();
        if (!$test['ok']) {
            throw new \RuntimeException('Refusing to reload sshd: config test failed: ' . $test['output']);
        }
        // Debian/Ubuntu service is "ssh"; some distros use "sshd".
        $r = $this->runner->run('systemctl', ['reload', 'ssh'], null, 20);
        if (!$r->isSuccess()) {
            $r = $this->runner->run('systemctl', ['reload', 'sshd'], null, 20);
        }
        if (!$r->isSuccess()) {
            throw new \RuntimeException('systemctl reload ssh failed: ' . $r->summary());
        }
    }

    /**
     * Make sure the main sshd_config pulls in the drop-in directory.
     * Modern Ubuntu ships this Include by default; if it is missing the
     * drop-in would be ignored, so we prepend it (Include must precede
     * any Match block to take effect). Returns true if we changed it.
     */
    private function ensureInclude(): bool
    {
        $content = $this->fs->readFile($this->mainConfig);
        if ($content === null) {
            // No main config at all - extremely unusual; don't fabricate it.
            throw new \RuntimeException('sshd_config not found: ' . $this->mainConfig);
        }
        if (preg_match('#^\s*Include\s+/etc/ssh/sshd_config\.d/\*\.conf#m', $content) === 1) {
            return false;
        }
        $line = "Include /etc/ssh/sshd_config.d/*.conf\n";
        $this->fs->writeAtomic($this->mainConfig, $line . $content, 0644);
        return true;
    }
}
