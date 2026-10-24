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
            'price' => number_format(fake()->randomFloat(2, 1, 100), 2, '.', ''),
        ];
    }
}
