<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Http\Resources\OrderResource;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    /**
     * @return array{status:int, body:array}
     */
    public function checkout(User $user, int $productId, int $quantity, string $idempotencyKey): array
    {
        $requestHash = hash('sha256', json_encode([
            'product_id' => $productId,
            'quantity' => $quantity,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $productId, $quantity, $idempotencyKey, $requestHash): array {
            // insertOrIgnore lets concurrent requests race on the unique
            // (user_id, key) constraint without throwing. The subsequent
            // lock makes the loser wait for the winner and then replay its
            // committed result.
            IdempotencyKey::query()->insertOrIgnore([
                'user_id' => $user->id,
                'key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'status' => 'processing',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $record = IdempotencyKey::query()
                ->where('user_id', $user->id)
                ->where('key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();

            if ($record->request_hash !== $requestHash) {
                return [
                    'status' => 409,
                    'body' => ['message' => 'This Idempotency-Key was already used with a different request.'],
                ];
            }

            if ($record->status === 'completed') {
                return ['status' => $record->response_status, 'body' => $record->response_body];
            }

            $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

            if ($product->stock < $quantity) {
                $body = ['message' => 'Insufficient stock for this product.'];

                $record->update([
                    'status' => 'completed',
                    'response_status' => 422,
                    'response_body' => $body,
                ]);

                return ['status' => 422, 'body' => $body];
            }

            $product->decrement('stock', $quantity);

            $order = Order::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price_rials' => $product->price_rials,
                'total_rials' => $product->price_rials * $quantity,
                'status' => OrderStatus::Pending,
            ]);

            $body = (new OrderResource($order))->response()->getData(true);

            $record->update([
                'status' => 'completed',
                'response_status' => 201,
                'response_body' => $body,
                'order_id' => $order->id,
            ]);

            return ['status' => 201, 'body' => $body];
        });
    }
}
