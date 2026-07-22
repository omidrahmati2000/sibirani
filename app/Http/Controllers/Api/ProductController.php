<?php

namespace App\Http\Controllers\Api;

use App\Services\ProductCatalogCache;
use Illuminate\Http\JsonResponse;

class ProductController
{
    public function index(ProductCatalogCache $catalogCache): JsonResponse
    {
        return response()->json(['data' => $catalogCache->remember()]);
    }
}
