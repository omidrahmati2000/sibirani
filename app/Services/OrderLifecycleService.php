<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderStateTransitionException;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class OrderLifecycleService
{
    public function cancel(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($lockedOrder->status !== OrderStatus::Pending) {
                throw new InvalidOrderStateTransitionException(
                    'Only pending orders can be cancelled.'
                );
            }

            $product = Product::query()->whereKey($lockedOrder->product_id)->lockForUpdate()->firstOrFail();
            $product->increment('stock', $lockedOrder->quantity);

            $lockedOrder->update(['status' => OrderStatus::Cancelled]);

            return $lockedOrder->refresh();
        });
    }

    public function refund(Order $order): Order
    {
        return DB::transaction(function () use ($order): Order {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! in_array($lockedOrder->status, [OrderStatus::Paid, OrderStatus::Delivered], true)) {
                throw new InvalidOrderStateTransitionException(
                    'Only paid or delivered orders can be refunded.'
                );
            }

            $lockedOrder->update(['status' => OrderStatus::Refunded]);

            return $lockedOrder->refresh();
        });
    }
}
