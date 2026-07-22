<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\InvalidOrderStateTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\CheckoutService;
use App\Services\OrderLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly OrderLifecycleService $orderLifecycleService,
    ) {}

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
        $this->authorize('viewAny', Order::class);

        $orders = Order::query()
            ->visibleTo($request->user())
            ->latest()
            ->paginate(20);

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

        try {
            $order = $this->orderLifecycleService->cancel($order);
        } catch (InvalidOrderStateTransitionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return new OrderResource($order);
    }

    public function refund(Order $order)
    {
        $this->authorize('refund', $order);

        try {
            $order = $this->orderLifecycleService->refund($order);
        } catch (InvalidOrderStateTransitionException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return new OrderResource($order);
    }
}
