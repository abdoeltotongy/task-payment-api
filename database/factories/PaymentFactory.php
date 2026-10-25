<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'method' => 'credit_card',
            'status' => PaymentStatus::Pending,
            'amount' => '10.00',
            'transaction_id' => null,
            'gateway_response' => null,
        ];
    }

    public function successful(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Successful,
            'is_successful' => true,
            'transaction_id' => 'txn_'.fake()->unique()->numerify('######'),
            'gateway_response' => ['message' => 'Payment approved.'],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::Failed,
            'transaction_id' => null,
            'gateway_response' => ['message' => 'Payment declined.'],
        ]);
    }
}
