<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverAccountJob;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'reference' => ['required', 'string'],
        ]);

        DB::transaction(function () use ($data) {
            $order = Order::whereKey($data['order_id'])->lockForUpdate()->firstOrFail();

            if ($order->status !== OrderStatus::Pending) {
                return; // already processed — idempotent no-op for webhook retries
            }

            $order->update([
                'status' => OrderStatus::Paid,
                'payment_reference' => $data['reference'],
                'paid_at' => now(),
            ]);

            DeliverAccountJob::dispatch($order->id)->afterCommit();
        });

        return response()->json(['message' => 'ok']);
    }
}
