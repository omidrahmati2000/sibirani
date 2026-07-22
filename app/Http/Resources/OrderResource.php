<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'quantity' => $this->quantity,
            'unit_price_rials' => $this->unit_price_rials,
            'total_rials' => $this->total_rials,
            'status' => $this->status->value,
            'paid_at' => $this->paid_at,
            'delivered_at' => $this->delivered_at,
            'created_at' => $this->created_at,
        ];
    }
}
