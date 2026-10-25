<?php

declare(strict_types=1);

namespace App\Payments\Contracts;

use App\Models\Payment;
use App\Payments\DTOs\GatewayResult;

interface PaymentGatewayInterface
{
    /**
     * Validation rules for the request `data` object.
     * Keys are field names, such as `card_number`, without the `data.` prefix.
     *
     * @return array<string, mixed>
     */
    public function rules(): array;

    /**
     * Run this gateway's simulated or provider-specific payment behavior.
     *
     * @param  array<string, mixed>  $data
     */
    public function process(Payment $payment, array $data = []): GatewayResult;
}
