<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStatusTransitionException;
use App\Exceptions\OrderHasPaymentsException;
use App\Models\Order;
use App\Models\User;
use App\Support\OrderTotals;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class OrderService
{
    public function __construct(private readonly OrderTotals $totals) {}

    /**
     * @param  array{status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        return Order::query()
            ->whereBelongsTo($user)
            ->when(
                isset($filters['status']),
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->with('items')
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * @param  array{notes?: string|null, items: list<array{product_name: string, quantity: int, price: int|float|string}>}  $data
     */
    public function create(User $user, array $data): Order
    {
        return DB::transaction(function () use ($user, $data): Order {
            $order = Order::query()->create([
                'user_id' => $user->id,
                'status' => OrderStatus::Pending,
                'total_amount' => $this->totals->sum($data['items']),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->replaceItems($order, $data['items']);

            return $order->load('items');
        });
    }

    /**
     * @param  array{notes?: string|null, items?: list<array{product_name: string, quantity: int, price: int|float|string}>}  $data
     */
    public function update(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data): Order {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if (array_key_exists('items', $data) && $locked->hasSuccessfulPayment()) {
                throw new OrderHasPaymentsException('Order items cannot be changed after a successful payment.');
            }

            if (array_key_exists('notes', $data)) {
                $locked->notes = $data['notes'];
            }

            if (array_key_exists('items', $data)) {
                $this->replaceItems($locked, $data['items']);
                $locked->total_amount = $this->totals->sum($data['items']);
            }

            $locked->save();

            return $locked->load('items');
        });
    }

    public function updateStatus(Order $order, OrderStatus $status): Order
    {
        if (! $this->canTransition($order->status, $status)) {
            throw new InvalidOrderStatusTransitionException(
                "Cannot change order status from {$order->status->value} to {$status->value}.",
            );
        }

        $order->status = $status;
        $order->save();

        return $order->load('items');
    }

    public function delete(Order $order): void
    {
        if ($order->payments()->exists()) {
            throw new OrderHasPaymentsException('Orders with payments cannot be deleted.');
        }

        $order->delete();
    }

    private function canTransition(OrderStatus $from, OrderStatus $to): bool
    {
        return match ($to) {
            OrderStatus::Confirmed => $from === OrderStatus::Pending,
            OrderStatus::Cancelled => $from === OrderStatus::Pending || $from === OrderStatus::Confirmed,
            OrderStatus::Pending => false,
        };
    }

    /**
     * @param  list<array{product_name: string, quantity: int, price: int|float|string}>  $items
     */
    private function replaceItems(Order $order, array $items): void
    {
        $order->items()->delete();

        $order->items()->createMany(array_map(
            fn (array $item): array => [
                'product_name' => $item['product_name'],
                'quantity' => (int) $item['quantity'],
                'price' => $this->totals->normalize($item['price']),
            ],
            $items,
        ));

        $order->unsetRelation('items');
    }
}
