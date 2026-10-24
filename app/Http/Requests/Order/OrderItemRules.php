<?php

declare(strict_types=1);

namespace App\Http\Requests\Order;

final class OrderItemRules
{
    /**
     * @return array<string, list<string>>
     */
    public static function itemFields(): array
    {
        return [
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.price' => ['required', 'numeric', 'min:0', 'decimal:0,2', 'max:9999999999.99'],
        ];
    }
}
