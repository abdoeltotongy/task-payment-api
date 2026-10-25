<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'method',
        'status',
        'is_successful',
        'amount',
        'transaction_id',
        'gateway_response',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'is_successful',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PaymentStatus::class,
            'is_successful' => 'boolean',
            'amount' => 'decimal:2',
            'gateway_response' => 'array',
        ];
    }

    /**
     * Store the outcome of a payment attempt.
     * `is_successful` is true only for an approved payment so the unique index allows one success per order.
     *
     * @param  array<string, mixed>  $raw
     */
    public function settle(bool $approved, ?string $transactionId, string $message, array $raw = []): void
    {
        $this->forceFill([
            'status' => $approved ? PaymentStatus::Successful : PaymentStatus::Failed,
            'is_successful' => $approved ? true : null,
            'transaction_id' => $transactionId,
            'gateway_response' => [
                'message' => $message,
                'raw' => $raw,
            ],
        ])->save();
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
