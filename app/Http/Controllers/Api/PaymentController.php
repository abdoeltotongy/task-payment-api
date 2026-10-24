<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\IndexPaymentRequest;
use App\Http\Requests\Payment\ProcessPaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Order;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function index(IndexPaymentRequest $request): AnonymousResourceCollection
    {
        return PaymentResource::collection(
            $this->payments->paginateForUser($request->user(), $request->validated()),
        );
    }

    public function show(Payment $payment): PaymentResource
    {
        $this->authorize('view', $payment);

        return new PaymentResource($payment);
    }

    public function indexForOrder(IndexPaymentRequest $request, Order $order): AnonymousResourceCollection
    {
        $this->authorize('view', $order);

        return PaymentResource::collection(
            $this->payments->paginateForOrder($request->user(), $order, $request->validated()),
        );
    }

    public function store(ProcessPaymentRequest $request, Order $order): JsonResponse
    {
        $payment = $this->payments->process($request->user(), $order, $request->validated());

        return (new PaymentResource($payment))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }
}
