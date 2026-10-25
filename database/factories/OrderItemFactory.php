<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'product_name' => fake()->words(3, true),
            'quantity' => fake()->numberBetween(1, 5),
            'price' => $this->price(),
        ];
    }

    private function price(): string
    {
        $cents = fake()->numberBetween(100, 10000);

        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
