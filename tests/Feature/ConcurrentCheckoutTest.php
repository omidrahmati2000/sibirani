<?php

namespace Tests\Feature;

use App\Exceptions\InsufficientStockException;
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

                $resultFile = sys_get_temp_dir() . '/checkout_child_' . getmypid() . '.json';

                $succeeded = false;
                $exceptionClass = null;

                try {
                    $result = app(CheckoutService::class)->checkout($user, $product->id, 1, (string) Str::uuid());
                    $succeeded = $result['status'] === 201;

                    if (! $succeeded) {
                        throw new InsufficientStockException();
                    }
                } catch (\Throwable $e) {
                    // Expected for buyers that lose the race once stock hits 0.
                    $exceptionClass = get_class($e);
                }

                // Child processes can't return PHP values to the parent, so
                // persist the outcome to a file the parent reads after waitpid.
                file_put_contents($resultFile, json_encode([
                    'succeeded' => $succeeded,
                    'exception_class' => $exceptionClass,
                ]));

                exit(0);
            }

            $pids[] = $pid;
            $resultFiles[] = sys_get_temp_dir() . '/checkout_child_' . $pid . '.json';
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
            \App\Models\Order::where('product_id', $product->id)->count(),
            'Exactly as many orders as available stock must have been created — no oversell.'
        );

        foreach ($results as $result) {
            if ($result['succeeded']) {
                continue;
            }

            // Losing buyers must fail with the clean, intended exception — not
            // a raw QueryException from the unsignedInteger column constraint,
            // which is what happens if ->lockForUpdate() is removed.
            $this->assertSame(
                InsufficientStockException::class,
                $result['exception_class'],
                'Losing buyers must fail with InsufficientStockException, not a raw DB error.'
            );
        }
    }
}
