<?php

declare(strict_types=1);

namespace FlowOne\Storage\Exceptions;

/**
 * Thrown by StorageHealth when no trustworthy state can be obtained from
 * any source (Redis, current JSON, backup JSON, hard-coded safe default).
 * In practice this should never happen — the safe default is the last
 * fallback — but the exception type exists for unrecoverable callers.
 */
final class StateUnavailableException extends \RuntimeException {}
