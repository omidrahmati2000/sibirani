<?php

namespace Tests\Feature;

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

        $this->assertSame(1, \App\Models\Order::count());
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
}
