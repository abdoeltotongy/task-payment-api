<?php

declare(strict_types=1);

namespace App\Payments\Gateways;

use App\Models\Payment;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\DTOs\GatewayResult;

/**
 * Simulated PayPal gateway. No request is sent to PayPal.
 * An email containing "fail" is declined. Any other valid email is approved.
 */
final class PayPalGateway implements PaymentGatewayInterface
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
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    public function process(Payment $payment, array $data = []): GatewayResult
    {
        $email = $data['email'] ?? '';

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return new GatewayResult(
                success: false,
                transactionId: null,
                message: 'PayPal details are invalid.',
            );
        }

        $declined = str_contains(strtolower($email), 'fail');

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
