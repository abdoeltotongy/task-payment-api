<?php

declare(strict_types=1);

namespace App\Payments\DTOs;

final readonly class GatewayResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $success,
        public ?string $transactionId,
        public string $message,
        public array $raw = [],
    ) {}
}
