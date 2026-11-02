<?php

namespace Maina888\Sms\Services;

use RuntimeException;
use Throwable;

/**
 * A send attempt that failed in a way worth retrying (network error, HTTP 5xx).
 */
class AfricasTalkingException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = true, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
