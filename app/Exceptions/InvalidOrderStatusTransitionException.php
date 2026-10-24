<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class InvalidOrderStatusTransitionException extends RuntimeException
{
    public function __construct(string $message = 'This order status change is not allowed.')
    {
        parent::__construct($message);
    }
}
