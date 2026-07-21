<?php

namespace Tests\Feature;

use App\Contracts\AccountDeliveryService;
use App\Enums\OrderStatus;
use App\Jobs\DeliverAccountJob;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\Fakes\FakeAccountDeliveryService;
use Tests\TestCase;

class AccountDeliveryJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_delivery_marks_order_delivered_with_payload(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Paid]);

        $this->app->instance(AccountDeliveryService::class, new FakeAccountDeliveryService([true]));

        (new DeliverAccountJob($order->id))->handle(app(AccountDeliveryService::class));

        $order->refresh();
        $this->assertSame(OrderStatus::Delivered, $order->status);
        $this->assertSame(1, $order->delivery_attempts);
        $this->assertNotNull($order->delivered_at);
        $this->assertArrayHasKey('apple_id', $order->delivery_payload);
    }

    public function test_failed_delivery_is_retried_with_exponential_backoff(): void
    {
        Queue::fake();

        $order = Order::factory()->create(['status' => OrderStatus::Paid]);
        $this->app->instance(AccountDeliveryService::class, new FakeAccountDeliveryService([false]));

        (new DeliverAccountJob($order->id))->handle(app(AccountDeliveryService::class));

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status, 'Order must not be Failed before exhausting retries.');
        $this->assertSame(1, $order->delivery_attempts);

        Queue::assertPushed(DeliverAccountJob::class, function (DeliverAccountJob $job) {
            return $job->orderId && $job->delay !== null && abs($job->delay->diffInSeconds(now())) >= 1;
        });
    }

    public function test_order_is_marked_failed_after_three_failed_attempts(): void
    {
        Queue::fake();

        $order = Order::factory()->create(['status' => OrderStatus::Paid, 'delivery_attempts' => 2]);
        $this->app->instance(AccountDeliveryService::class, new FakeAccountDeliveryService([false]));

        (new DeliverAccountJob($order->id))->handle(app(AccountDeliveryService::class));

        $order->refresh();
        $this->assertSame(OrderStatus::Failed, $order->status);
        $this->assertSame(3, $order->delivery_attempts);
        Queue::assertNotPushed(DeliverAccountJob::class);
    }
}
