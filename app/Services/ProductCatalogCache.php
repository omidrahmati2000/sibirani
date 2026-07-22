<?php

namespace App\Services;

use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class ProductCatalogCache
{
    private const KEY = 'products.catalog.v1';

    public function remember(): array
    {
        return Cache::remember(self::KEY, now()->addMinutes(5), function (): array {
            return ProductResource::collection(
                Product::query()->orderBy('id')->get(),
            )->resolve();
        });
    }

    public function forget(): void
    {
        Cache::forget(self::KEY);
    }
}
