<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class PaymentProviderException extends RuntimeException
{
    public function __construct(
        string $message = 'The payment provider could not complete the payment.',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
