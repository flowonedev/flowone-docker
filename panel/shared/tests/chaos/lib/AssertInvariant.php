<?php

declare(strict_types=1);

namespace FlowOne\Storage\Chaos;

use FlowOne\Storage\Config;
use FlowOne\Storage\HmacSigner;

/**
 * Confirms a named invariant held throughout a chaos scenario.
 *
 * Strategy: invariant violations are recorded in the operation journal
 * as `invariant_violation` events with `context.invariant = "I-N"`. This
 * helper scans the journal slice produced during the scenario window and
 * asserts that no violation for the named invariant was recorded.
 *
 * The scenario calls:
 *
 *   $assert = AssertInvariant::startWindow(['I-9', 'I-14']);
 *   ...
 *   $assert->assertHeld();   // throws if any of the listed invariants
 *                            // were violated since startWindow()
 *
 * Scenarios that ARE supposed to trigger a controlled violation (e.g.
 * to verify the violation is detected and reported) can call
 * assertViolated('I-9') instead.
 */
final class AssertInvariant
{
    private function __construct(
        private readonly array $invariantIds,
        private readonly int $windowStartUnix,
        private readonly string $journalPath,
        private readonly HmacSigner $signer,
    ) {}

    /**
     * @param list<string> $invariantIds  e.g. ['I-9', 'I-14']
     */
    public static function startWindow(array $invariantIds): self
    {
        $config = Config::load();
        $signer = HmacSigner::fromKeyFile(
            (string) $config['state']['hmac_key_path'],
            (int) $config['state']['hmac_key_mode_max']
        );
        return new self(
            $invariantIds,
            time(),
            (string) $config['journal']['path'],
            $signer,
        );
    }

    /**
     * Confirms none of the tracked invariants were violated in the window.
     * Throws RuntimeException on first violation found.
     */
    public function assertHeld(): void
    {
        $violations = $this->scanViolations();
        $bad = array_intersect_key($violations, array_flip($this->invariantIds));
        if (!empty($bad)) {
            $detail = json_encode($bad, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            throw new \RuntimeException("Invariant(s) violated during scenario window:\n{$detail}");
        }
    }

    /**
     * Confirms a SPECIFIC invariant WAS violated (used by scenarios that
     * deliberately trigger a controlled break).
     */
    public function assertViolated(string $invariantId): void
    {
        $violations = $this->scanViolations();
        if (empty($violations[$invariantId])) {
            throw new \RuntimeException(
                "Expected violation of {$invariantId} during scenario window, but none recorded"
            );
        }
    }

    /**
     * Returns map: invariantId => list of journal entries.
     */
    private function scanViolations(): array
    {
        if (!is_file($this->journalPath)) {
            return [];
        }
        $fh = @fopen($this->journalPath, 'rb');
        if ($fh === false) {
            return [];
        }
        try {
            $out = [];
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $verified = $this->signer->verifyJson($line);
                if ($verified === null) {
                    continue;
                }
                if (($verified['event'] ?? null) !== 'invariant_violation') {
                    continue;
                }
                if ((int) ($verified['ts_unix'] ?? 0) < $this->windowStartUnix) {
                    continue;
                }
                $id = $verified['context']['invariant'] ?? null;
                if (!is_string($id)) {
                    continue;
                }
                $out[$id][] = $verified;
            }
            return $out;
        } finally {
            @fclose($fh);
        }
    }
}
