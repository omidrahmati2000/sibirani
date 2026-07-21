<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;

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
            $request->header('Idempotency-Key'),
        );

        return response()->json($result['body'], $result['status']);
    }
}
