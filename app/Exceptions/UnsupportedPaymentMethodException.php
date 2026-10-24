<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class UnsupportedPaymentMethodException extends RuntimeException
{
    public function __construct(string $method)
    {
        parent::__construct("Payment method [{$method}] is not supported.");
    }
}
