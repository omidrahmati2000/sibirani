<?php

namespace Tests\Support\Fakes;

use App\Contracts\AccountDeliveryService;
use App\Exceptions\AccountDeliveryFailedException;
use App\Models\Order;

class FakeAccountDeliveryService implements AccountDeliveryService
{
    /** @var array<int, bool> queue of outcomes: true = succeed, false = fail */
    private array $outcomes;

    private int $calls = 0;

    public function __construct(array $outcomes)
    {
        $this->outcomes = $outcomes;
    }

    public function deliver(Order $order): array
    {
        $outcome = $this->outcomes[$this->calls] ?? end($this->outcomes);
        $this->calls++;

        if (! $outcome) {
            throw new AccountDeliveryFailedException('Fake failure');
        }

        return ['apple_id' => 'fake@icloud-store.example', 'temporary_password' => 'fakepass'];
    }

    public function callCount(): int
    {
        return $this->calls;
    }
}
