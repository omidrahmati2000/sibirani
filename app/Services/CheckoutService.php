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

        // Claim the idempotency key as its own, already-committed operation
        // (Eloquent's create() outside an explicit DB::transaction() commits
        // immediately). This must NOT live inside the transaction below: if it
        // did, a stock-check failure inside that transaction would roll back
        // this insert too, and the catch block further down would find no row
        // to mark as "completed" — silently losing the idempotency guarantee
        // for failed checkouts.
        try {
            $record = IdempotencyKey::create([
                'user_id' => $user->id,
                'key' => $idempotencyKey,
                'status' => 'processing',
            ]);
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

        try {
            [$order, $body] = DB::transaction(function () use ($user, $productId, $quantity) {
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
                    'unit_price_rials' => $product->price_rials,
                    'total_rials' => $product->price_rials * $quantity,
                    'status' => OrderStatus::Pending,
                ]);

                $body = (new OrderResource($order))->response()->getData(true);

                return [$order, $body];
            });

            // Outside the transaction that could still roll back around it:
            // by the time we get here the order/stock changes are committed,
            // so record the completed outcome as a separate statement.
            $record->update([
                'status' => 'completed',
                'response_status' => 201,
                'response_body' => $body,
                'order_id' => $order->id,
            ]);

            return ['status' => 201, 'body' => $body];
        } catch (InsufficientStockException $e) {
            // The inner transaction rolled back only the product lock/order
            // creation attempt; the "processing" idempotency key row created
            // above was never part of that transaction, so it still exists
            // and this update actually finds and completes it.
            $record->update([
                'status' => 'completed',
                'response_status' => 422,
                'response_body' => ['message' => $e->getMessage()],
            ]);

            return ['status' => 422, 'body' => ['message' => $e->getMessage()]];
        }
    }
}
