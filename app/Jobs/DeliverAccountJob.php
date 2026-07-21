<?php

namespace App\Jobs;

use App\Contracts\AccountDeliveryService;
use App\Enums\OrderStatus;
use App\Exceptions\AccountDeliveryFailedException;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 3;

    public int $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle(AccountDeliveryService $service): void
    {
        $order = Order::findOrFail($this->orderId);
        $attempt = $order->delivery_attempts + 1;

        try {
            $payload = $service->deliver($order);

            $order->update([
                'status' => OrderStatus::Delivered,
                'delivery_attempts' => $attempt,
                'delivery_payload' => $payload,
                'delivered_at' => now(),
            ]);
        } catch (AccountDeliveryFailedException) {
            $order->update(['delivery_attempts' => $attempt]);

            if ($attempt >= self::MAX_ATTEMPTS) {
                $order->update(['status' => OrderStatus::Failed]);

                return;
            }

            $delaySeconds = 2 ** $attempt; // 2s, 4s, 8s

            self::dispatch($this->orderId)->delay(now()->addSeconds($delaySeconds));
        }
    }
}
