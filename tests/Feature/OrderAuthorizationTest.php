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

        Sanctum::actingAs($admin);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
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
}
