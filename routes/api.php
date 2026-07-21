<?php

use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'idempotency.required'])->group(function () {
    Route::post('/orders', [OrderController::class, 'store']);
});

Route::post('/webhooks/payment', PaymentWebhookController::class)
    ->middleware('webhook.signature');
