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

class ConcurrentIdempotencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateDatabaseTables();

        parent::tearDown();
    }

    public function test_concurrent_requests_with_the_same_key_create_one_order_and_replay_one_response(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock' => 1]);
        $idempotencyKey = (string) Str::uuid();

        DB::disconnect();

        $pids = [];
        $resultFiles = [];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }

            if ($pid === 0) {
                DB::reconnect();

                $resultFile = sys_get_temp_dir().'/idempotency_child_'.getmypid().'.json';
                $result = null;

                try {
                    $result = app(CheckoutService::class)->checkout(
                        $user,
                        $product->id,
                        1,
                        $idempotencyKey,
                    );
                } catch (\Throwable $exception) {
                    $result = ['exception_class' => get_class($exception)];
                }

                file_put_contents($resultFile, json_encode($result));

                exit(0);
            }

            $pids[] = $pid;
            $resultFiles[] = sys_get_temp_dir().'/idempotency_child_'.$pid.'.json';
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();

        $results = [];
        foreach ($resultFiles as $resultFile) {
            $this->assertFileExists($resultFile);
            $results[] = json_decode(file_get_contents($resultFile), true);
            unlink($resultFile);
        }

        $this->assertSame(0, $product->fresh()->stock);
        $this->assertSame(1, Order::where('product_id', $product->id)->count());

        foreach ($results as $result) {
            $this->assertArrayNotHasKey('exception_class', $result);
            $this->assertSame(201, $result['status']);
        }

        $this->assertSame(
            $this->sortKeysRecursively($results[0]['body']),
            $this->sortKeysRecursively($results[1]['body']),
        );
    }

    /** @param array<string|int, mixed> $value */
    private function sortKeysRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeysRecursively($item);
            }
        }

        ksort($value);

        return $value;
    }
}
