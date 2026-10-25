<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Payment;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\DTOs\GatewayResult;
use RuntimeException;

final class ExplodingPaymentGateway implements PaymentGatewayInterface
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
        throw new RuntimeException('provider exploded for payment '.$payment->id);
    }
}
