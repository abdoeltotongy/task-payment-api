<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

final class ApiExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        [$status, $message, $errors] = $this->resolve($exception);

        return response()->json([
            'message' => $message,
            'errors' => $errors === [] ? (object) [] : $errors,
        ], $status);
    }

    /**
     * @return array{0: int, 1: string, 2: array<string, mixed>}
     */
    private function resolve(Throwable $exception): array
    {
        if ($exception instanceof ValidationException) {
            return [
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                $exception->getMessage() !== '' ? $exception->getMessage() : 'The given data was invalid.',
                $exception->errors(),
            ];
        }

        if (
            $exception instanceof AuthenticationException
            || $exception instanceof JWTException
            || $exception instanceof UnauthorizedHttpException
        ) {
            return [
                JsonResponse::HTTP_UNAUTHORIZED,
                $this->message($exception, 'Unauthenticated.'),
                [],
            ];
        }

        if ($exception instanceof AuthorizationException || $exception instanceof AccessDeniedHttpException) {
            return [
                JsonResponse::HTTP_FORBIDDEN,
                $this->message($exception, 'This action is unauthorized.'),
                [],
            ];
        }

        if ($exception instanceof ModelNotFoundException || $exception instanceof NotFoundHttpException) {
            return [JsonResponse::HTTP_NOT_FOUND, 'Resource not found.', []];
        }

        if ($exception instanceof OrderNotConfirmedException || $exception instanceof UnsupportedPaymentMethodException) {
            return [
                JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
                $this->message($exception, 'Request failed.'),
                [],
            ];
        }

        if (
            $exception instanceof OrderHasPaymentsException
            || $exception instanceof PaymentAlreadyProcessedException
            || $exception instanceof InvalidOrderStatusTransitionException
        ) {
            return [
                JsonResponse::HTTP_CONFLICT,
                $this->message($exception, 'Request failed.'),
                [],
            ];
        }

        if ($exception instanceof HttpExceptionInterface) {
            return [
                $exception->getStatusCode(),
                $this->message($exception, 'Request failed.'),
                [],
            ];
        }

        $message = config('app.debug') === true
            ? $exception->getMessage()
            : 'Server error.';

        return [
            JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            $message !== '' ? $message : 'Server error.',
            [],
        ];
    }

    private function message(Throwable $exception, string $fallback): string
    {
        $message = $exception->getMessage();

        return $message !== '' ? $message : $fallback;
    }
}
