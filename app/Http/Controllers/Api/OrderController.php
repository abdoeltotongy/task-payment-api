<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Order\IndexOrderRequest;
use App\Http\Requests\Order\StoreOrderRequest;
use App\Http\Requests\Order\UpdateOrderRequest;
use App\Http\Requests\Order\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class OrderController extends Controller
{
    public function __construct(private readonly OrderService $orders) {}

    public function index(IndexOrderRequest $request): AnonymousResourceCollection
    {
        return OrderResource::collection(
            $this->orders->paginate($request->user(), $request->validated()),
        );
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $order = $this->orders->create($request->user(), $request->validated());

        return (new OrderResource($order))
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    public function show(Order $order): OrderResource
    {
        $this->authorize('view', $order);

        return new OrderResource($order->load('items'));
    }

    public function update(UpdateOrderRequest $request, Order $order): OrderResource
    {
        return new OrderResource($this->orders->update($order, $request->validated()));
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): OrderResource
    {
        return new OrderResource(
            $this->orders->updateStatus($order, OrderStatus::from($request->validated('status'))),
        );
    }

    public function destroy(Order $order): Response
    {
        $this->authorize('delete', $order);
        $this->orders->delete($order);

        return response()->noContent();
    }
}
