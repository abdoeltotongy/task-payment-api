<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $demo = User::query()->firstOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => 'password'],
        );

        User::query()->firstOrCreate(
            ['email' => 'other@example.com'],
            ['name' => 'Other User', 'password' => 'password'],
        );

        if ($demo->orders()->exists()) {
            return;
        }

        $orders = app(OrderService::class);
        $payments = app(PaymentService::class);

        $orders->create($demo, [
            'notes' => 'Pending delivery',
            'items' => [
                ['product_name' => 'Cement', 'quantity' => 2, 'price' => '10.25'],
                ['product_name' => 'Sand', 'quantity' => 1, 'price' => '3.00'],
            ],
        ]);

        $paid = $orders->updateStatus($orders->create($demo, [
            'notes' => 'Paid order',
            'items' => [
                ['product_name' => 'Gravel', 'quantity' => 4, 'price' => '2.50'],
            ],
        ]), OrderStatus::Confirmed);

        $payments->process($demo, $paid, [
            'method' => 'credit_card',
            'data' => ['card_number' => '4242424242424242'],
        ]);

        $declined = $orders->updateStatus($orders->create($demo, [
            'notes' => 'Card was declined',
            'items' => [
                ['product_name' => 'Blocks', 'quantity' => 10, 'price' => '1.75'],
            ],
        ]), OrderStatus::Confirmed);

        $payments->process($demo, $declined, [
            'method' => 'credit_card',
            'data' => ['card_number' => '4000000000000000'],
        ]);

        $paypal = $orders->updateStatus($orders->create($demo, [
            'items' => [
                ['product_name' => 'Rebar', 'quantity' => 3, 'price' => '8.40'],
            ],
        ]), OrderStatus::Confirmed);

        $payments->process($demo, $paypal, [
            'method' => 'paypal',
            'data' => ['email' => 'fail@example.com'],
        ]);

        $orders->updateStatus($orders->create($demo, [
            'notes' => 'Customer cancelled',
            'items' => [
                ['product_name' => 'Paint', 'quantity' => 1, 'price' => '15.00'],
            ],
        ]), OrderStatus::Cancelled);

        $other = User::query()->where('email', 'other@example.com')->firstOrFail();

        $orders->create($other, [
            'notes' => 'Belongs to another user',
            'items' => [
                ['product_name' => 'Nails', 'quantity' => 5, 'price' => '1.20'],
            ],
        ]);
    }
}
