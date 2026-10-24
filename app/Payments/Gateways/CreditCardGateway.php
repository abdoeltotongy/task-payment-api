<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\DTOs\GatewayResult;

final class CreditCardGateway implements PaymentGatewayInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = []) {}

    public function process(Payment $payment, array $data = []): GatewayResult
    {
        $cardNumber = (string) ($data['card_number'] ?? '');
        $declined = str_ends_with($cardNumber, '0000');

        return new GatewayResult(
            success: ! $declined,
            transactionId: $declined ? null : 'cc_'.$payment->id,
            message: $declined ? 'Card declined.' : 'Payment approved.',
            raw: [
                'card_last4' => substr($cardNumber, -4),
            ],
        );
    }
}
