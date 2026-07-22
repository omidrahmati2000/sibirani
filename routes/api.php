<?php

use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/orders', [OrderController::class, 'store'])
        ->middleware(['idempotency.required', 'throttle:checkout']);
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{order}/refund', [OrderController::class, 'refund']);
});

Route::post('/webhooks/payment', PaymentWebhookController::class)
    ->middleware(['throttle:payment-webhook', 'webhook.signature']);

Route::get('/products', [ProductController::class, 'index'])
    ->middleware('throttle:products');
