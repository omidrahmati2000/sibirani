<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'unit_price_rials' => $this->unit_price_rials,
            'total_rials' => $this->total_rials,
            'status' => $this->status->value,
            'paid_at' => $this->paid_at,
            'delivered_at' => $this->delivered_at,
            'delivery_payload' => $this->when(
                $this->status === OrderStatus::Delivered
                    && $request->user()?->id === $this->user_id
                    && $request->route('order') instanceof Order,
                $this->delivery_payload,
            ),
            'created_at' => $this->created_at,
        ];
    }
}
