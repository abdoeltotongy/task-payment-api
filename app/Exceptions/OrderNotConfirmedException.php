<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class OrderNotConfirmedException extends RuntimeException
{
    public function __construct(string $message = 'Payments can only be processed for confirmed orders.')
    {
        parent::__construct($message);
    }
}
