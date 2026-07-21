<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        $product = Product::factory()->create();
        $quantity = 1;

        return [
            'user_id' => User::factory(),
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price_cents' => $product->price_cents,
            'total_cents' => $product->price_cents * $quantity,
            'status' => OrderStatus::Pending,
        ];
    }
}
