<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_check_out_an_available_product(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 5, 'price_cents' => 1999]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 2,
        ], [
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.total_cents', 3998);

        $this->assertSame(3, $product->fresh()->stock);
        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    public function test_checkout_fails_with_422_when_stock_is_insufficient(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 1]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 5,
        ], [
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $response->assertStatus(422);
        $this->assertSame(1, $product->fresh()->stock);
    }
}
