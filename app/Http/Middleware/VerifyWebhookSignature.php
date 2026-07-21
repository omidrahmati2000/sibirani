<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Signature', '');
        $secret = (string) config('services.payment_gateway.webhook_secret');

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, (string) $signature)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        return $next($request);
    }
}
