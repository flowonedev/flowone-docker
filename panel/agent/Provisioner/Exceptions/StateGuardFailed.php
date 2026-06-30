<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Exceptions;

/**
 * Thrown when a state-transition guard fails:
 *   - The current state in DB does not match the expected `from` state
 *     (concurrent modification, lost update, stale read).
 *   - The site row disappeared between read and transition.
 *
 * The caller should refetch the row, decide whether to retry, and never
 * blindly retry the same transition.
 */
class StateGuardFailed extends \RuntimeException
{
}
