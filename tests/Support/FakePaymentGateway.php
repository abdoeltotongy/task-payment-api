<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Payment;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\DTOs\GatewayResult;

final class FakePaymentGateway implements PaymentGatewayInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = []) {}

    public function rules(): array
    {
        return [];
    }

    public function process(Payment $payment, array $data = []): GatewayResult
    {
        return new GatewayResult(
            success: true,
            transactionId: 'fake_'.$payment->id,
            message: 'Fake gateway approved the payment.',
            raw: ['mode' => $this->config['mode'] ?? null],
        );
    }
}
