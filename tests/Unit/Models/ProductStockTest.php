<?php

namespace Tests\Unit\Models;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_can_be_created_with_stock(): void
    {
        $product = Product::factory()->create(['stock' => 10]);

        $this->assertSame(10, $product->fresh()->stock);
    }
}
