<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Step;

/**
 * One observable event produced during a step's execution.
 *
 * Persisted to the `site_job_events` table by the worker so the UI can
 * stream live progress over SSE. Higher granularity than step_executions
 * (which is one row per ATTEMPT) - a single step attempt can emit many
 * events ("starting", "calling vault", "5/10 done", "warning: slow",
 * "finished").
 *
 * Always pass through SecretMasker before passing user-controlled
 * metadata: that's done by the worker's `recordEvent()` helper, not
 * by this DTO. Steps should still avoid putting secrets in metadata to
 * begin with - log key fingerprints and references, not values.
 */
final class StepEvent
{
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    public function __construct(
        public readonly string $level,
        public readonly string $message,
        public readonly array $metadata = [],
        public readonly ?\DateTimeImmutable $occurredAt = null
    ) {
    }

    public static function debug(string $message, array $metadata = []): self
    {
        return new self(self::LEVEL_DEBUG, $message, $metadata, new \DateTimeImmutable('now'));
    }

    public static function info(string $message, array $metadata = []): self
    {
        return new self(self::LEVEL_INFO, $message, $metadata, new \DateTimeImmutable('now'));
    }

    public static function warning(string $message, array $metadata = []): self
    {
        return new self(self::LEVEL_WARNING, $message, $metadata, new \DateTimeImmutable('now'));
    }

    public static function error(string $message, array $metadata = []): self
    {
        return new self(self::LEVEL_ERROR, $message, $metadata, new \DateTimeImmutable('now'));
    }

    public function withMetadata(array $extra): self
    {
        return new self(
            $this->level,
            $this->message,
            array_merge($this->metadata, $extra),
            $this->occurredAt,
        );
    }
}
