<?php

declare(strict_types=1);

namespace App\Payments\Contracts;

use App\Models\Payment;
use App\Payments\DTOs\GatewayResult;

interface PaymentGatewayInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function process(Payment $payment, array $data = []): GatewayResult;
}
