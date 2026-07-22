<?php

namespace App\Contracts;

use App\Exceptions\AccountDeliveryFailedException;
use App\Models\Order;

interface AccountDeliveryService
{
    /**
     * @return array<string, mixed> delivery payload (e.g. account credentials)
     *
     * @throws AccountDeliveryFailedException
     */
    public function deliver(Order $order): array;
}
