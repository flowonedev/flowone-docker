<?php

declare(strict_types=1);

namespace VpsAdmin\Agent\Provisioner\Exceptions;

/**
 * Base exception for SecretVault problems (missing master key, decrypt
 * failure, key not found, corrupted ciphertext). Sub-classes give the
 * specific reason but all callers can catch this and degrade gracefully.
 */
class VaultException extends \RuntimeException
{
}
