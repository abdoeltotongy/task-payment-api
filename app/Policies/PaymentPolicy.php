<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

final class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        return $payment->order()->where('user_id', $user->id)->exists();
    }
}
