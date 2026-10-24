<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => OrderStatus::Pending,
            'total_amount' => '0.00',
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Confirmed]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Cancelled]);
    }
}
