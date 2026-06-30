<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Adapters;

use VpsAdmin\Agent\Provisioner\Ols\Ast\Document;
use VpsAdmin\Agent\Provisioner\Ols\OlsConfigMutator;
use VpsAdmin\Agent\Provisioner\Ols\OlsConfigParser;
use VpsAdmin\Agent\Provisioner\Ols\OlsConfigValidator;
use VpsAdmin\Agent\Provisioner\Ols\OlsConfigWriter;

/**
 * Adapter for OpenLiteSpeed - the only HTTP server in this stack.
 *
 * Composes:
 *   - OlsConfigParser/Mutator/Writer/Validator (Step 2 building blocks)
 *   - CommandRunner for lswsctrl invocations
 *   - FilesystemAdapter for vhost.conf I/O
 *
 * Architecture rules this respects:
 *   - Adapters may not call other adapters. The composition with
 *     FilesystemAdapter is allowed only because FilesystemAdapter is
 *     injected as a collaborator, not invoked transitively. Each
 *     adapter still has a single OS responsibility.
 *   - Steps NEVER touch the OLS config files directly. They go through
 *     OlsAdapter::loadMainConfig() / writeMainConfig() / etc. so
 *     formatting, atomic writes, and validation are uniform.
 *
 * What it does NOT do:
 *   - Restart coordination - that's OlsRestartCoordinator's job, which
 *     wraps this adapter's restart() with debouncing + locking.
 *   - State machine transitions - that's SiteStateMachine.
 *   - Audit logging - that's the step's responsibility.
 */
final class OlsAdapter
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly FilesystemAdapter $fs,
        private readonly string $lswsctrlBin = '/usr/local/lsws/bin/lswsctrl',
        private readonly string $configRoot = '/usr/local/lsws/conf',
        private readonly OlsConfigParser $parser = new OlsConfigParser(),
        private readonly OlsConfigMutator $mutator = new OlsConfigMutator(),
        private readonly OlsConfigValidator $validator = new OlsConfigValidator(),
        private readonly ?OlsConfigWriter $writer = null
    ) {
    }

    // ─── lswsctrl wrappers ────────────────────────────────────

    /**
     * Raw graceful restart. Most callers should go through
     * OlsRestartCoordinator::request() to coalesce concurrent restarts.
     */
    public function restart(int $timeoutSeconds = 30): CommandResult
    {
        return $this->runner->run($this->lswsctrlBin, ['restart'], null, $timeoutSeconds);
    }

    public function stop(int $timeoutSeconds = 30): CommandResult
    {
        return $this->runner->run($this->lswsctrlBin, ['stop'], null, $timeoutSeconds);
    }

    public function start(int $timeoutSeconds = 30): CommandResult
    {
        return $this->runner->run($this->lswsctrlBin, ['start'], null, $timeoutSeconds);
    }

    public function isRunning(): bool
    {
        // `lswsctrl status` exits 0 when the server is running and prints
        // "litespeed is running with PID NNN."  We use the exit code
        // alone because the localized strings vary across builds.
        return $this->runner->run($this->lswsctrlBin, ['status'], null, 5)->isSuccess();
    }

    /**
     * lswsctrl has no "config test" subcommand; we approximate it by
     * having callers stage their candidate file and ask us to parse it.
     * For hard syntax validation we lean on OlsConfigValidator.
     */
    public function testConfig(string $candidatePath): CommandResult
    {
        // Self-parse as a baseline. Returning a CommandResult-shaped
        // value lets callers handle this the same way they would any
        // shell-test command.
        $startedAt = microtime(true);
        try {
            $this->parser->parseFile($candidatePath);
            return new CommandResult(
                exitCode: 0,
                stdout: 'self-parse ok',
                stderr: '',
                durationSeconds: microtime(true) - $startedAt,
                timedOut: false,
                commandLine: "ols-config-test {$candidatePath}",
            );
        } catch (\Throwable $e) {
            return new CommandResult(
                exitCode: 1,
                stdout: '',
                stderr: $e->getMessage(),
                durationSeconds: microtime(true) - $startedAt,
                timedOut: false,
                commandLine: "ols-config-test {$candidatePath}",
            );
        }
    }

    // ─── Main config (httpd_config.conf) ──────────────────────

    public function mainConfigPath(): string
    {
        return $this->configRoot . '/httpd_config.conf';
    }

    /**
     * Load httpd_config.conf into an AST. Throws if the file is
     * unparseable - the caller should NOT try to write back into a
     * broken file.
     */
    public function loadMainConfig(): Document
    {
        return $this->parser->parseFile($this->mainConfigPath());
    }

    /**
     * Write the (presumably mutated) Document back to httpd_config.conf.
     * Runs structural validation BEFORE the atomic swap so a bad AST
     * never lands on disk.
     *
     * @return array{
     *   target: string,
     *   bytes: int,
     *   timestamped_backup: string,
     *   rolling_backup: string,
     *   pruned: int
     * }
     */
    public function writeMainConfig(Document $doc): array
    {
        $this->validator->assertStructural($doc);
        $writer = $this->writer ?? $this->buildDefaultWriter();
        return $writer->write(
            $this->mainConfigPath(),
            $doc,
            // Validator on the staged file: parse it back. Catches any
            // bug in our renderer before the rename().
            function (string $stagedPath): void {
                $r = $this->testConfig($stagedPath);
                if (!$r->isSuccess()) {
                    throw new \RuntimeException(
                        "staged main config failed self-test: " . $r->stderr
                    );
                }
            }
        );
    }

    // ─── vhost.conf files (one per site) ──────────────────────

    public function vhostConfigDir(string $domain): string
    {
        return $this->configRoot . '/vhosts/' . $domain;
    }

    public function vhostConfigPath(string $domain): string
    {
        return $this->vhostConfigDir($domain) . '/vhost.conf';
    }

    public function vhostConfigExists(string $domain): bool
    {
        return $this->fs->isFile($this->vhostConfigPath($domain));
    }

    public function readVhostConfig(string $domain): ?string
    {
        $path = $this->vhostConfigPath($domain);
        return $this->fs->readFile($path);
    }

    /**
     * Write a per-vhost config file. Creates the parent directory if
     * needed. Atomic.
     */
    public function writeVhostConfig(string $domain, string $content): void
    {
        $dir = $this->vhostConfigDir($domain);
        $this->fs->ensureDirectory($dir);
        $path = $this->vhostConfigPath($domain);
        $this->fs->writeAtomic($path, $content);
    }

    /**
     * Remove a vhost's config directory + its log dir if present. Used
     * by the cleanup step on site deletion. Returns the number of
     * filesystem entries removed.
     */
    public function removeVhostConfig(string $domain): int
    {
        $dir = $this->vhostConfigDir($domain);
        if (!$this->fs->exists($dir)) {
            return 0;
        }
        return $this->fs->rmtree($dir);
    }

    // ─── AST helpers (delegates so callers don't have to wire) ─

    public function mutator(): OlsConfigMutator
    {
        return $this->mutator;
    }

    public function parser(): OlsConfigParser
    {
        return $this->parser;
    }

    public function validator(): OlsConfigValidator
    {
        return $this->validator;
    }

    // ─── Internals ────────────────────────────────────────────

    private function buildDefaultWriter(): OlsConfigWriter
    {
        // Wire the writer's chown/chmod through our CommandRunner so
        // tests can mock both layers from a single seam.
        $runner = $this->runner;
        return new OlsConfigWriter(
            execCommand: \Closure::fromCallable(
                function (string $binary, array $args) use ($runner): array {
                    $r = $runner->run($binary, $args, null, 5);
                    return [
                        'exit' => $r->exitCode,
                        'stdout' => $r->stdout,
                        'stderr' => $r->stderr,
                    ];
                }
            ),
        );
    }
}
