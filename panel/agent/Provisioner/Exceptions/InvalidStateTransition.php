<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Exceptions;

/**
 * Thrown by SiteStateMachine when a transition is not in the allowed map.
 * Catching this anywhere outside the state machine itself is a bug -
 * the caller should not be attempting illegal transitions.
 */
class InvalidStateTransition extends \DomainException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly string $reason = ''
    ) {
        $msg = "Illegal state transition: {$from} -> {$to}";
        if ($reason !== '') {
            $msg .= " ({$reason})";
        }
        parent::__construct($msg);
    }
}
