<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_catalog_is_rate_limited(): void
    {
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->getJson('/api/products')->assertOk();
        }

        $this->getJson('/api/products')->assertStatus(429);
    }

    public function test_checkout_is_rate_limited_per_authenticated_user(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 20]);

        Sanctum::actingAs($user);

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson('/api/orders', [
                'product_id' => $product->id,
                'quantity' => 1,
            ], [
                'Idempotency-Key' => (string) Str::uuid(),
            ])->assertCreated();
        }

        $this->postJson('/api/orders', [
            'product_id' => $product->id,
            'quantity' => 1,
        ], [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->assertStatus(429);
    }

    public function test_payment_webhook_is_rate_limited_per_ip(): void
    {
        config(['services.payment_gateway.webhook_secret' => 'test-secret']);

        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->call('POST', '/api/webhooks/payment', [], [], [], [
                'HTTP_X-Signature' => 'invalid',
                'CONTENT_TYPE' => 'application/json',
            ], '{"order_id":1,"reference":"rate-limit-test"}')
                ->assertStatus(401);
        }

        $this->call('POST', '/api/webhooks/payment', [], [], [], [
            'HTTP_X-Signature' => 'invalid',
            'CONTENT_TYPE' => 'application/json',
        ], '{"order_id":1,"reference":"rate-limit-test"}')
            ->assertStatus(429);
    }
}
