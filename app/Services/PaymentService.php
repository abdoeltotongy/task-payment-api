<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderNotConfirmedException;
use App\Exceptions\PaymentAlreadyProcessedException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\PaymentGatewayManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class PaymentService
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    /**
     * @param  array{status?: string, method?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Payment>
     */
    public function paginateForUser(User $user, array $filters): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->whereHas('order', fn (Builder $query) => $query->where('user_id', $user->id))
            ->latest('id')
            ->paginate($this->perPage($filters));
    }

    /**
     * @param  array{status?: string, method?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, Payment>
     */
    public function paginateForOrder(User $user, Order $order, array $filters): LengthAwarePaginator
    {
        $this->ensureOwner($user, $order);

        return $this->filteredQuery($filters)
            ->where('order_id', $order->id)
            ->latest('id')
            ->paginate($this->perPage($filters));
    }

    /**
     * @param  array{method: string, data?: array<string, mixed>}  $data
     */
    public function process(User $user, Order $order, array $data): Payment
    {
        return DB::transaction(function () use ($user, $order, $data): Payment {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($locked->user_id !== $user->id || $locked->status !== OrderStatus::Confirmed) {
                throw new OrderNotConfirmedException;
            }

            if ($locked->hasSuccessfulPayment()) {
                throw new PaymentAlreadyProcessedException;
            }

            $payment = $locked->payments()->create([
                'method' => $data['method'],
                'status' => PaymentStatus::Pending,
                'amount' => $locked->total_amount,
            ]);

            $result = $this->gateways->driver($data['method'])->process($payment, $data['data'] ?? []);

            $payment->update([
                'status' => $result->success ? PaymentStatus::Successful : PaymentStatus::Failed,
                'transaction_id' => $result->transactionId,
                'gateway_response' => [
                    'message' => $result->message,
                    'raw' => $result->raw,
                ],
            ]);

            return $payment->refresh();
        });
    }

    private function ensureOwner(User $user, Order $order): void
    {
        if ($order->user_id !== $user->id) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    /**
     * @param  array{status?: string, method?: string, per_page?: int}  $filters
     * @return Builder<Payment>
     */
    private function filteredQuery(array $filters): Builder
    {
        return Payment::query()
            ->when(
                isset($filters['status']),
                fn (Builder $query) => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['method']),
                fn (Builder $query) => $query->where('method', $filters['method']),
            );
    }

    /**
     * @param  array{per_page?: int}  $filters
     */
    private function perPage(array $filters): int
    {
        return (int) ($filters['per_page'] ?? 15);
    }
}
