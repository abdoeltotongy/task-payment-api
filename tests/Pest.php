<?php

declare(strict_types=1);

use App\Models\User;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');

function tokenFor(User $user): string
{
    $token = app('tymon.jwt')->fromUser($user);

    if (! is_string($token)) {
        throw new RuntimeException('Failed to issue a JWT.');
    }

    app('tymon.jwt')->unsetToken();

    return $token;
}

function resetAuthState(): void
{
    if (app()->bound('tymon.jwt')) {
        app('tymon.jwt')->unsetToken();
    }

    auth()->forgetGuards();
}

/**
 * @return array<string, string>
 */
function asUser(User $user): array
{
    resetAuthState();

    return ['Authorization' => 'Bearer '.tokenFor($user)];
}
