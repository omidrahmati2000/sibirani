<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'product_id', 'quantity', 'unit_price_rials', 'total_rials',
        'status', 'payment_reference', 'paid_at', 'delivery_attempts',
        'delivery_payload', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'delivery_payload' => 'array',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
