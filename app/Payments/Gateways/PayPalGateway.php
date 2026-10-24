<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\DTOs\GatewayResult;

final class PayPalGateway implements PaymentGatewayInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = []) {}

    public function process(Payment $payment, array $data = []): GatewayResult
    {
        $email = (string) ($data['email'] ?? '');
        $declined = str_contains($email, 'fail');

        return new GatewayResult(
            success: ! $declined,
            transactionId: $declined ? null : 'pp_'.$payment->id,
            message: $declined ? 'PayPal payment declined.' : 'PayPal payment approved.',
            raw: [
                'email' => $email,
            ],
        );
    }
}
