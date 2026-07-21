<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcurrentCheckoutTest extends TestCase
{
    use DatabaseTruncation;

    public function test_concurrent_checkouts_cannot_oversell_the_last_units(): void
    {
        $stock = 3;
        $concurrentBuyers = 10;

        $product = Product::factory()->create(['stock' => $stock]);
        $users = User::factory()->count($concurrentBuyers)->create();

        // Close the parent's DB connection before forking so children don't
        // share a single TCP socket to MySQL.
        DB::disconnect();

        $pids = [];

        foreach ($users as $user) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }

            if ($pid === 0) {
                // Child process: fresh connection, attempt to buy 1 unit.
                DB::reconnect();

                try {
                    app(CheckoutService::class)->checkout($user, $product->id, 1);
                } catch (\Throwable) {
                    // Expected for buyers that lose the race once stock hits 0.
                }

                exit(0);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();

        $product->refresh();

        $this->assertSame(0, $product->stock, 'Stock must never go negative or under-decrement.');
        $this->assertSame(
            $stock,
            \App\Models\Order::where('product_id', $product->id)->count(),
            'Exactly as many orders as available stock must have been created — no oversell.'
        );
    }
}
