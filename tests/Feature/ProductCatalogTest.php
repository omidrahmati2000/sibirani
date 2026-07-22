<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_catalog_returns_products_without_authentication(): void
    {
        $product = Product::factory()->create([
            'name' => 'Apple ID',
            'slug' => 'apple-id',
            'price_rials' => 1999,
            'stock' => 5,
        ]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.price_rials', 1999)
            ->assertJsonPath('data.0.stock', 5);
    }

    public function test_checkout_invalidates_the_cached_catalog(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 5]);

        $this->getJson('/api/products')->assertJsonPath('data.0.stock', 5);

        Sanctum::actingAs($user);
        $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 2,
        ], [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertCreated();

        $this->getJson('/api/products')->assertJsonPath('data.0.stock', 3);
    }

    public function test_cancellation_invalidates_the_cached_catalog(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 4]);
        $order = Order::factory()->create([
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => OrderStatus::Pending,
        ]);

        $this->getJson('/api/products')->assertJsonPath('data.0.stock', 4);

        Sanctum::actingAs($admin);
        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();

        $this->getJson('/api/products')->assertJsonPath('data.0.stock', 6);
    }
}
