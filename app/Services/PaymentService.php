<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\OrderNotConfirmedException;
use App\Exceptions\PaymentAlreadyProcessedException;
use App\Exceptions\PaymentProviderException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Payments\Contracts\PaymentGatewayInterface;
use App\Payments\DTOs\GatewayResult;
use App\Payments\PaymentGatewayManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PaymentService
{
    /**
     * Response keys that must never be stored. Gateway results should already omit these.
     *
     * @var list<string>
     */
    private const REDACTED_KEYS = [
        'card_number',
        'cvv',
        'cvc',
        'pan',
        'api_key',
        'secret',
        'client_secret',
        'password',
        'authorization',
    ];

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
     * Create a pending payment, ask the gateway, then store the outcome.
     *
     * The gateway call sits outside the database transaction so a provider request
     * never runs while the order row is locked. A declined result is stored as failed
     * and returned to the caller. An unexpected gateway exception is stored as failed,
     * logged, and rethrown so the API does not report success.
     *
     * @param  array{method: string, data?: array<string, mixed>}  $data
     */
    public function process(User $user, Order $order, array $data): Payment
    {
        $gateway = $this->gateways->driver($data['method']);
        $payload = $this->validatedPayload($gateway, $data['data'] ?? []);
        $payment = $this->startAttempt($user, $order, $data['method']);

        try {
            $result = $gateway->process($payment, $payload);
        } catch (Throwable $exception) {
            try {
                $this->failAttempt($payment);
            } catch (Throwable $updateException) {
                $this->logGatewayFailure($payment, $updateException);
            }

            $this->logGatewayFailure($payment, $exception);

            throw new PaymentProviderException(previous: $exception);
        }

        return $this->applyResult($payment, $result);
    }

    private function ensureOwner(User $user, Order $order): void
    {
        if ($order->user_id !== $user->id) {
            throw new AuthorizationException('This action is unauthorized.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(PaymentGatewayInterface $gateway, mixed $data): array
    {
        if (! is_array($data)) {
            throw ValidationException::withMessages([
                'data' => ['The data field must be an array.'],
            ]);
        }

        $rules = [];

        foreach ($gateway->rules() as $field => $fieldRules) {
            $rules['data.'.$field] = $fieldRules;
        }

        if ($rules !== []) {
            Validator::make(['data' => $data], $rules)->validate();
        }

        return $data;
    }

    private function startAttempt(User $user, Order $order, string $method): Payment
    {
        return DB::transaction(function () use ($user, $order, $method): Payment {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->user_id !== $user->id) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            if ($locked->status !== OrderStatus::Confirmed) {
                throw new OrderNotConfirmedException;
            }

            if ($locked->hasSuccessfulPayment()) {
                throw new PaymentAlreadyProcessedException;
            }

            return $locked->payments()->create([
                'method' => $method,
                'status' => PaymentStatus::Pending,
                'amount' => $locked->total_amount,
            ]);
        });
    }

    private function applyResult(Payment $payment, GatewayResult $result): Payment
    {
        try {
            $decision = $this->storeResult($payment, $result);
        } catch (UniqueConstraintViolationException $exception) {
            $this->logGatewayFailure($payment, $exception);
            $this->failAttempt($payment);

            throw new PaymentAlreadyProcessedException;
        }

        if ($decision === 'not_confirmed') {
            throw new OrderNotConfirmedException;
        }

        if ($decision === 'duplicate') {
            throw new PaymentAlreadyProcessedException;
        }

        return $payment->refresh();
    }

    private function storeResult(Payment $payment, GatewayResult $result): string
    {
        return DB::transaction(function () use ($payment, $result): string {
            $lockedOrder = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->status !== PaymentStatus::Pending) {
                return 'done';
            }

            if ($lockedOrder->status !== OrderStatus::Confirmed) {
                $lockedPayment->settle(false, null, 'Payments can only be processed for confirmed orders.');

                return 'not_confirmed';
            }

            if ($result->success && $lockedOrder->hasSuccessfulPayment()) {
                $lockedPayment->settle(false, null, 'A successful payment already exists for this order.');

                return 'duplicate';
            }

            $lockedPayment->settle(
                $result->success,
                $result->success ? $result->transactionId : null,
                $result->message,
                $this->redact($result->raw),
            );

            return 'done';
        });
    }

    private function failAttempt(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== PaymentStatus::Pending) {
                return;
            }

            $locked->settle(false, null, 'The payment provider could not complete the payment.');
        });
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function redact(array $raw): array
    {
        $clean = [];

        foreach ($raw as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $clean;
    }

    private function logGatewayFailure(Payment $payment, Throwable $exception): void
    {
        Log::error('Payment gateway failed.', [
            'payment_id' => $payment->id,
            'order_id' => $payment->order_id,
            'method' => $payment->method,
            'exception' => $exception::class,
        ]);
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
