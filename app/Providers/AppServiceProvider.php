<?php

namespace App\Providers;

use App\Contracts\AccountDeliveryService;
use App\Models\Order;
use App\Policies\OrderPolicy;
use App\Services\Delivery\RandomFailureAccountDeliveryService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            AccountDeliveryService::class,
            RandomFailureAccountDeliveryService::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Order::class, OrderPolicy::class);

        RateLimiter::for('checkout', fn (Request $request) => Limit::perMinute(10)
            ->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('payment-webhook', fn (Request $request) => Limit::perMinute(60)
            ->by((string) $request->ip()));

        RateLimiter::for('products', fn (Request $request) => Limit::perMinute(30)
            ->by((string) $request->ip()));
    }
}
