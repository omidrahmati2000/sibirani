<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_view_own_order(): void
    {
        $owner = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id]);

        Sanctum::actingAs($owner);

        $this->getJson("/api/orders/{$order->id}")->assertOk();
    }

    public function test_customer_cannot_view_another_customers_order(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id]);

        Sanctum::actingAs($intruder);

        $this->getJson("/api/orders/{$order->id}")->assertForbidden();
    }

    public function test_admin_can_view_any_order(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['user_id' => $owner->id]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/orders/{$order->id}")->assertOk();
    }

    public function test_customer_order_index_contains_only_owned_orders(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Order::factory()->create(['user_id' => $owner->id]);
        Order::factory()->create(['user_id' => $other->id]);

        Sanctum::actingAs($owner);

        $response = $this->getJson('/api/orders')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($owner->id, $response->json('data.0.user_id'));
    }

    public function test_admin_order_index_contains_all_orders(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Order::factory()->count(2)->create();

        Sanctum::actingAs($admin);

        $this->getJson('/api/orders')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_only_the_order_owner_can_receive_delivered_credentials(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create([
            'user_id' => $owner->id,
            'status' => OrderStatus::Delivered,
            'delivery_payload' => [
                'apple_id' => 'buyer@example.com',
                'temporary_password' => 'secret',
            ],
        ]);

        Sanctum::actingAs($owner);
        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.delivery_payload.apple_id', 'buyer@example.com');

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonMissingPath('data.0.delivery_payload');

        Sanctum::actingAs($admin);
        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.delivery_payload');
    }

    public function test_customer_cannot_cancel_an_order(): void
    {
        $owner = User::factory()->create();
        $order = Order::factory()->create(['user_id' => $owner->id, 'status' => OrderStatus::Pending]);

        Sanctum::actingAs($owner);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertForbidden();
    }

    public function test_admin_can_cancel_an_order(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['user_id' => $owner->id, 'status' => OrderStatus::Pending]);
        $stockBeforeCancel = $order->product->stock;

        Sanctum::actingAs($admin);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame($stockBeforeCancel + $order->quantity, $order->product->fresh()->stock);
    }

    public function test_admin_cannot_cancel_a_paid_order(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Paid]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertStatus(409);

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_admin_can_refund_a_paid_order(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['user_id' => $owner->id, 'status' => OrderStatus::Paid]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/orders/{$order->id}/refund")->assertOk();
        $this->assertSame(OrderStatus::Refunded, $order->fresh()->status);
    }

    public function test_admin_cannot_refund_a_pending_order(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/orders/{$order->id}/refund")
            ->assertStatus(409);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }
}
