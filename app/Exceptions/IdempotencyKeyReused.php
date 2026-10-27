<?php

namespace App\Exceptions;

use RuntimeException;

class IdempotencyKeyReused extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This payment session was already used for a different invoice or amount. Please start again.');
    }
}
