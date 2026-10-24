<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class PaymentAlreadyProcessedException extends RuntimeException
{
    public function __construct(string $message = 'A successful payment already exists for this order.')
    {
        parent::__construct($message);
    }
}
