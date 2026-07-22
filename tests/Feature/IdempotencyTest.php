<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeating_the_same_idempotency_key_does_not_create_a_duplicate_order(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);
        Sanctum::actingAs($user);

        $key = (string) Str::uuid();
        $payload = ['product_id' => $product->id, 'quantity' => 1];

        $first = $this->postJson('/api/orders', $payload, ['Idempotency-Key' => $key]);
        $second = $this->postJson('/api/orders', $payload, ['Idempotency-Key' => $key]);

        $first->assertCreated();
        $second->assertStatus($first->getStatusCode());
        $second->assertJson($first->json());

        $this->assertSame(1, Order::count());
        $this->assertSame(9, $product->fresh()->stock);
    }

    public function test_missing_idempotency_key_header_is_rejected(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', ['product_id' => $product->id, 'quantity' => 1]);

        $response->assertStatus(422);
    }

    public function test_reusing_a_key_with_a_different_payload_is_rejected(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 10]);
        Sanctum::actingAs($user);

        $key = (string) Str::uuid();

        $this->postJson('/api/orders', ['product_id' => $product->id, 'quantity' => 1], [
            'Idempotency-Key' => $key,
        ])->assertCreated();

        $this->postJson('/api/orders', ['product_id' => $product->id, 'quantity' => 2], [
            'Idempotency-Key' => $key,
        ])->assertStatus(409);

        $this->assertSame(1, Order::count());
        $this->assertSame(9, $product->fresh()->stock);
    }

    public function test_insufficient_stock_response_is_replayed_by_idempotency(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 0]);
        Sanctum::actingAs($user);

        $key = (string) Str::uuid();
        $payload = ['product_id' => $product->id, 'quantity' => 1];

        $first = $this->postJson('/api/orders', $payload, ['Idempotency-Key' => $key]);
        $second = $this->postJson('/api/orders', $payload, ['Idempotency-Key' => $key]);

        $first->assertStatus(422);
        $second->assertStatus(422)->assertJson($first->json());
        $this->assertSame(0, Order::count());
    }

    public function test_idempotency_key_cannot_exceed_database_key_length(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/orders', [
            'product_id' => Product::factory()->create()->id,
            'quantity' => 1,
        ], [
            'Idempotency-Key' => str_repeat('x', 256),
        ]);

        $response->assertStatus(422);
    }
}
