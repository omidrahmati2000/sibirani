<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Http\Resources\OrderResource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    /**
     * @return array{status:int, body:array}
     */
    public function checkout(User $user, int $productId, int $quantity, string $idempotencyKey): array
    {
        $existing = IdempotencyKey::query()
            ->where('user_id', $user->id)
            ->where('key', $idempotencyKey)
            ->first();

        if ($existing && $existing->status === 'completed') {
            return ['status' => $existing->response_status, 'body' => $existing->response_body];
        }

        try {
            return DB::transaction(function () use ($user, $productId, $quantity, $idempotencyKey) {
                $record = IdempotencyKey::create([
                    'user_id' => $user->id,
                    'key' => $idempotencyKey,
                    'status' => 'processing',
                ]);

                // Required even though `stock` is unsignedInteger: that column
                // constraint only stops stock going negative, and does so by
                // throwing a raw uncaught QueryException — it does not provide
                // the clean InsufficientStockException contract below, nor does
                // it protect races that aren't decrement-based. The row lock is
                // what makes losing requests fail cleanly and predictably.
                $product = Product::query()->whereKey($productId)->lockForUpdate()->firstOrFail();

                if ($product->stock < $quantity) {
                    throw new InsufficientStockException();
                }

                $product->decrement('stock', $quantity);

                $order = Order::create([
                    'user_id' => $user->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price_cents' => $product->price_cents,
                    'total_cents' => $product->price_cents * $quantity,
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
        } catch (InsufficientStockException $e) {
            IdempotencyKey::where('user_id', $user->id)->where('key', $idempotencyKey)
                ->update(['status' => 'completed', 'response_status' => 422, 'response_body' => ['message' => $e->getMessage()]]);

            return ['status' => 422, 'body' => ['message' => $e->getMessage()]];
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'idempotency_keys_user_id_key_unique')) {
                // Lost the race to claim this key to a concurrent identical request.
                $winner = IdempotencyKey::where('user_id', $user->id)->where('key', $idempotencyKey)->first();

                if ($winner && $winner->status === 'completed') {
                    return ['status' => $winner->response_status, 'body' => $winner->response_body];
                }

                return ['status' => 409, 'body' => ['message' => 'This request is already being processed.']];
            }

            throw $e;
        }
    }
}
