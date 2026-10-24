<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class OrderHasPaymentsException extends RuntimeException
{
    public function __construct(string $message = 'Orders with payments cannot be deleted.')
    {
        parent::__construct($message);
    }
}
