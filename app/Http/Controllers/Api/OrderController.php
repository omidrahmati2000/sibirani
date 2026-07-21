<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private readonly CheckoutService $checkoutService)
    {
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $result = $this->checkoutService->checkout(
            $request->user(),
            (int) $request->validated('product_id'),
            (int) $request->validated('quantity'),
            trim((string) $request->header('Idempotency-Key')),
        );

        return response()->json($result['body'], $result['status']);
    }

    public function index(Request $request)
    {
        $orders = $request->user()->isAdmin()
            ? Order::query()->latest()->paginate(20)
            : $request->user()->orders()->latest()->paginate(20);

        return OrderResource::collection($orders);
    }

    public function show(Order $order)
    {
        $this->authorize('view', $order);

        return new OrderResource($order);
    }

    public function cancel(Order $order)
    {
        $this->authorize('cancel', $order);

        $order->update(['status' => OrderStatus::Cancelled]);

        return new OrderResource($order);
    }

    public function refund(Order $order)
    {
        $this->authorize('refund', $order);

        $order->update(['status' => OrderStatus::Refunded]);

        return new OrderResource($order);
    }
}
