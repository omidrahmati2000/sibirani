<?php

namespace App\Services\Delivery;

use App\Contracts\AccountDeliveryService;
use App\Exceptions\AccountDeliveryFailedException;
use App\Models\Order;

class RandomFailureAccountDeliveryService implements AccountDeliveryService
{
    public function __construct(
        private readonly int $minLatencyMs = 200,
        private readonly int $maxLatencyMs = 800,
        private readonly float $failureRate = 0.3,
    ) {}

    public function deliver(Order $order): array
    {
        usleep(random_int($this->minLatencyMs, $this->maxLatencyMs) * 1000);

        if ((mt_rand() / mt_getrandmax()) < $this->failureRate) {
            throw new AccountDeliveryFailedException("Simulated delivery failure for order {$order->id}");
        }

        return [
            'apple_id' => 'buyer'.$order->id.'@icloud-store.example',
            'temporary_password' => bin2hex(random_bytes(6)),
        ];
    }
}
