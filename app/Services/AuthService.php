<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use PHPOpenSourceSaver\JWTAuth\JWTGuard;

final class AuthService
{
    /**
     * @param  array{name: string, email: string, password: string}  $data
     * @return array{user: User, token: string}
     */
    public function register(array $data): array
    {
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
        ]);

        return [
            'user' => $user,
            'token' => $this->guard()->login($user),
        ];
    }

    /**
     * @param  array{email: string, password: string}  $credentials
     * @return array{user: User, token: string}
     */
    public function login(array $credentials): array
    {
        $token = $this->guard()->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ]);

        if (! is_string($token)) {
            throw new AuthenticationException('Invalid credentials.');
        }

        $user = $this->guard()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Invalid credentials.');
        }

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    public function logout(): void
    {
        $this->guard()->logout();
    }

    /**
     * @return array{user: User, token: string}
     */
    public function refresh(): array
    {
        $guard = $this->guard();
        $user = $guard->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return [
            'user' => $user,
            'token' => $guard->refresh(),
        ];
    }

    public function me(): User
    {
        $user = $this->guard()->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }

    public function tokenTtlInSeconds(): int
    {
        return (int) $this->guard()->getTTL() * 60;
    }

    private function guard(): JWTGuard
    {
        /** @var JWTGuard $guard */
        $guard = auth()->guard('api');

        return $guard;
    }
}
