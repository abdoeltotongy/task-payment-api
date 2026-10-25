<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Payment;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\DTOs\GatewayResult;

final class ReferencePaymentGateway implements PaymentGatewayInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = []) {}

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'min:3', 'max:40'],
        ];
    }

    public function process(Payment $payment, array $data = []): GatewayResult
    {
        $reference = (string) ($data['reference'] ?? '');

        return new GatewayResult(
            success: true,
            transactionId: 'ref_'.$payment->id,
            message: 'Transfer accepted.',
            raw: [
                'reference' => $reference,
                'bank_name' => $this->config['bank_name'] ?? null,
            ],
        );
    }
}
