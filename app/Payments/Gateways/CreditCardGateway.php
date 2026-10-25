<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\DTOs\GatewayResult;

/**
 * Simulated card gateway. No request is sent to a card processor.
 * A card number ending in 0000 is declined. Anything else that passes validation is approved.
 */
final class CreditCardGateway implements PaymentGatewayInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = [])
    {
        // Simulator only. Provider credentials are injected here and are not sent anywhere.
    }

    public function rules(): array
    {
        return [
            'card_number' => ['required', 'string', 'regex:/^\d{13,19}$/'],
        ];
    }

    public function process(Payment $payment, array $data = []): GatewayResult
    {
        $cardNumber = $data['card_number'] ?? '';

        if (! is_string($cardNumber) || preg_match('/^\d{13,19}$/', $cardNumber) !== 1) {
            return new GatewayResult(
                success: false,
                transactionId: null,
                message: 'Card details are invalid.',
            );
        }

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
