<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireIdempotencyKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasHeader('Idempotency-Key') || trim($request->header('Idempotency-Key')) === '') {
            return response()->json(['message' => 'The Idempotency-Key header is required.'], 422);
        }

        return $next($request);
    }
}
