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
        $product = Product::factory()->create(['stock' => 5, 'price_rials' => 1999]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 2,
        ], [
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.total_rials', 3998);

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

    public function test_checkout_supports_one_billion_rials_as_an_integer_amount(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'price_rials' => 1_000_000_000,
            'stock' => 2,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 2,
        ], [
            'Idempotency-Key' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.unit_price_rials', 1_000_000_000)
            ->assertJsonPath('data.total_rials', 2_000_000_000);

        $this->assertDatabaseHas('orders', [
            'unit_price_rials' => 1_000_000_000,
            'total_rials' => 2_000_000_000,
        ]);
    }
}
