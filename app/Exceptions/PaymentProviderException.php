<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentProviderException extends RuntimeException
{
    /**
     * @param  bool  $retryable  true when retrying with the same attempt is safe
     */
    public function __construct(string $message, public readonly bool $retryable)
    {
        parent::__construct($message);
    }
}
