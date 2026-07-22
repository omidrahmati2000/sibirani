<?php

namespace Tests\Feature;

use App\Contracts\AccountDeliveryService;
use App\Enums\OrderStatus;
use App\Exceptions\AccountDeliveryFailedException;
use App\Jobs\DeliverAccountJob;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_non_paid_order_is_not_delivered(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $service = new FakeAccountDeliveryService([true]);

        (new DeliverAccountJob($order->id))->handle($service);

        $order->refresh();
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(0, $order->delivery_attempts);
        $this->assertNull($order->delivered_at);
        $this->assertSame(0, $service->callCount());
    }

    public function test_failed_delivery_is_retried_with_exponential_backoff(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Paid]);
        $this->app->instance(AccountDeliveryService::class, new FakeAccountDeliveryService([false]));
        $job = new DeliverAccountJob($order->id);

        try {
            $job->handle(app(AccountDeliveryService::class));
            $this->fail('A delivery failure must be rethrown for the queue worker.');
        } catch (AccountDeliveryFailedException) {
            // The queue worker will release this job according to backoff().
        }

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status, 'Order must not be Failed before exhausting retries.');
        $this->assertSame(1, $order->delivery_attempts);
        $this->assertSame([2, 4], $job->backoff());
        $this->assertSame(3, $job->tries);
    }

    public function test_order_is_marked_failed_after_three_failed_attempts(): void
    {
        $order = Order::factory()->create(['status' => OrderStatus::Paid, 'delivery_attempts' => 2]);
        $this->app->instance(AccountDeliveryService::class, new FakeAccountDeliveryService([false]));
        $job = new DeliverAccountJob($order->id);

        try {
            $job->handle(app(AccountDeliveryService::class));
            $this->fail('A delivery failure must be rethrown for the queue worker.');
        } catch (AccountDeliveryFailedException $exception) {
            $job->failed($exception);
        }

        $order->refresh();
        $this->assertSame(OrderStatus::Failed, $order->status);
        $this->assertSame(3, $order->delivery_attempts);
    }
}
