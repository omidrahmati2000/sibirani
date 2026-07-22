<?php

namespace App\Jobs;

use App\Contracts\AccountDeliveryService;
use App\Enums\OrderStatus;
use App\Exceptions\AccountDeliveryFailedException;
use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class DeliverAccountJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 3;

    public int $tries = self::MAX_ATTEMPTS;

    public int $orderId;

    public function __construct(int $orderId)
    {
        $this->orderId = $orderId;
    }

    public function handle(AccountDeliveryService $service): void
    {
        $order = Order::findOrFail($this->orderId);

        if ($order->status !== OrderStatus::Paid) {
            return;
        }

        $attempt = $order->delivery_attempts + 1;

        try {
            $payload = $service->deliver($order);

            $order->update([
                'status' => OrderStatus::Delivered,
                'delivery_attempts' => $attempt,
                'delivery_payload' => $payload,
                'delivered_at' => now(),
            ]);
        } catch (AccountDeliveryFailedException $exception) {
            $order->update(['delivery_attempts' => $attempt]);

            throw $exception;
        }
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [2, 4];
    }

    public function failed(?Throwable $exception): void
    {
        Order::query()
            ->whereKey($this->orderId)
            ->where('status', OrderStatus::Paid)
            ->update([
                'status' => OrderStatus::Failed,
                'delivery_attempts' => self::MAX_ATTEMPTS,
            ]);
    }
}
