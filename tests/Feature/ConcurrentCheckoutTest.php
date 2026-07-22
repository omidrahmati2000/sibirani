<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\CheckoutService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConcurrentCheckoutTest extends TestCase
{
    use DatabaseTruncation;

    // Forked children commit directly to the DB outside any test
    // transaction, so the rows this test creates survive past its own
    // teardown and would otherwise leak into later tests that assert
    // absolute counts (e.g. IdempotencyTest). Truncate again on the way
    // out so this test leaves the database exactly as clean as it found it.
    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

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
        $resultFiles = [];

        foreach ($users as $user) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }

            if ($pid === 0) {
                // Child process: fresh connection, attempt to buy 1 unit.
                DB::reconnect();

                $resultFile = sys_get_temp_dir().'/checkout_child_'.getmypid().'.json';

                $succeeded = false;
                $status = null;
                $exceptionClass = null;

                try {
                    // CheckoutService::checkout() no longer throws for
                    // insufficient stock — it returns a 422 body instead.
                    // Record the real, actual outcome (status code, or a
                    // genuinely thrown exception's class) rather than
                    // synthesizing an exception from the status code, so this
                    // test reflects real behavior instead of a tautology.
                    $result = app(CheckoutService::class)->checkout($user, $product->id, 1, (string) Str::uuid());
                    $succeeded = $result['status'] === 201;
                    $status = $result['status'];
                } catch (\Throwable $e) {
                    // Not expected in the current implementation, but if some
                    // other real error path throws, capture it honestly.
                    $exceptionClass = get_class($e);
                }

                // Child processes can't return PHP values to the parent, so
                // persist the outcome to a file the parent reads after waitpid.
                file_put_contents($resultFile, json_encode([
                    'succeeded' => $succeeded,
                    'status' => $status,
                    'exception_class' => $exceptionClass,
                ]));

                exit(0);
            }

            $pids[] = $pid;
            $resultFiles[] = sys_get_temp_dir().'/checkout_child_'.$pid.'.json';
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();

        $results = [];
        foreach ($resultFiles as $resultFile) {
            $this->assertFileExists($resultFile, 'Every child process must have written its result file.');
            $results[] = json_decode(file_get_contents($resultFile), true);
            unlink($resultFile);
        }

        $product->refresh();

        $this->assertSame(0, $product->stock, 'Stock must never go negative or under-decrement.');
        $this->assertSame(
            $stock,
            Order::where('product_id', $product->id)->count(),
            'Exactly as many orders as available stock must have been created — no oversell.'
        );

        foreach ($results as $result) {
            if ($result['succeeded']) {
                continue;
            }

            // Losing buyers must get a clean 422 insufficient-stock response
            // from CheckoutService, not an unhandled exception (e.g. a raw
            // QueryException from the unsignedInteger column constraint,
            // which is what happens if ->lockForUpdate() is removed) and not
            // an unrelated status such as a 409 from the idempotency race
            // path (each child uses a unique idempotency key, so 409 should
            // never occur here).
            $this->assertNull(
                $result['exception_class'],
                'Losing buyers must not throw — CheckoutService should catch InsufficientStockException internally and return a 422 body.'
            );
            $this->assertSame(
                422,
                $result['status'],
                'Losing buyers must receive a 422 insufficient-stock response, not a raw DB error or other status.'
            );
        }
    }
}
