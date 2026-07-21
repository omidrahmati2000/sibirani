<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\DeliverAccountJob;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function sign(array $payload): array
    {
        $body = json_encode($payload);
        $signature = hash_hmac('sha256', $body, config('services.payment_gateway.webhook_secret'));

        return [$body, $signature];
    }

    public function test_valid_webhook_marks_order_paid_and_enqueues_delivery(): void
    {
        Queue::fake();

        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        [$body, $signature] = $this->sign(['order_id' => $order->id, 'reference' => 'pg_ref_123']);

        $response = $this->call('POST', '/api/webhooks/payment', [], [], [],
            ['HTTP_X-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);

        $response->assertOk();
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        Queue::assertPushed(DeliverAccountJob::class, fn (DeliverAccountJob $job) => $job->orderId === $order->id);
    }

    public function test_invalid_signature_is_rejected_with_401(): void
    {
        Queue::fake();

        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $body = json_encode(['order_id' => $order->id, 'reference' => 'pg_ref_123']);

        $response = $this->call('POST', '/api/webhooks/payment', [], [], [],
            ['HTTP_X-Signature' => 'not-the-right-signature', 'CONTENT_TYPE' => 'application/json'], $body);

        $response->assertStatus(401);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        Queue::assertNotPushed(DeliverAccountJob::class);
    }

    public function test_missing_webhook_secret_fails_closed_with_500(): void
    {
        Queue::fake();

        config(['services.payment_gateway.webhook_secret' => '']);

        $order = Order::factory()->create(['status' => OrderStatus::Pending]);
        $body = json_encode(['order_id' => $order->id, 'reference' => 'pg_ref_123']);
        $signature = hash_hmac('sha256', $body, '');

        $response = $this->call('POST', '/api/webhooks/payment', [], [], [],
            ['HTTP_X-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'], $body);

        $response->assertStatus(500);
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
        Queue::assertNotPushed(DeliverAccountJob::class);
    }
}
