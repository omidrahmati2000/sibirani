<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CheckoutService
{
    public function checkout(User $user, int $productId, int $quantity): Order
    {
        return DB::transaction(function () use ($user, $productId, $quantity) {
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

            return Order::create([
                'user_id' => $user->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'unit_price_cents' => $product->price_cents,
                'total_cents' => $product->price_cents * $quantity,
                'status' => OrderStatus::Pending,
            ]);
        });
    }
}
