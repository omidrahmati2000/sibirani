<?php

namespace App\Contracts;

use App\Models\Order;

interface AccountDeliveryService
{
    /**
     * @return array<string, mixed> delivery payload (e.g. account credentials)
     *
     * @throws \App\Exceptions\AccountDeliveryFailedException
     */
    public function deliver(Order $order): array;
}
