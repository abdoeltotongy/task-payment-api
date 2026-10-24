<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    public function update(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    public function updateStatus(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    public function delete(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    public function pay(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    private function owns(User $user, Order $order): bool
    {
        return $user->id === $order->user_id;
    }
}
