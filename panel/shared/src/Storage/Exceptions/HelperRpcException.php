<?php

declare(strict_types=1);

namespace FlowOne\Storage\Exceptions;

/**
 * Thrown by HelperClient when an RPC call to the privileged helper fails:
 * - socket missing or unreachable
 * - peer credential mismatch
 * - timeout
 * - helper returned an error response
 */
final class HelperRpcException extends \RuntimeException {}
