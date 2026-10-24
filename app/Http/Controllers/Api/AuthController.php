<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;

final class AuthController extends Controller
{
    public function __construct(private readonly AuthService $auth) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return $this->tokenResponse(
            $this->auth->register($request->validated()),
            'Registered successfully.',
            JsonResponse::HTTP_CREATED,
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return $this->tokenResponse(
            $this->auth->login($request->validated()),
            'Logged in successfully.',
            JsonResponse::HTTP_OK,
        );
    }

    public function logout(): JsonResponse
    {
        $this->auth->logout();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function refresh(): JsonResponse
    {
        return $this->tokenResponse(
            $this->auth->refresh(),
            'Token refreshed successfully.',
            JsonResponse::HTTP_OK,
        );
    }

    public function me(): UserResource
    {
        return new UserResource($this->auth->me());
    }

    /**
     * @param  array{user: User, token: string}  $result
     */
    private function tokenResponse(array $result, string $message, int $status): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'data' => new UserResource($result['user']),
            'access_token' => $result['token'],
            'token_type' => 'bearer',
            'expires_in' => $this->auth->tokenTtlInSeconds(),
        ], $status);
    }
}
