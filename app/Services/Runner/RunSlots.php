<?php

declare(strict_types=1);

namespace App\Services\Runner;

use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * A fixed number of concurrency slots, held as cache locks.
 *
 * Rate limiting caps how often one visitor may run something; this caps how much
 * the machine can be doing at once, across everyone. Without it, N simultaneous
 * requests mean N containers each allowed half a core and 128MB, and the host
 * starts swapping. The locks carry a short TTL so a PHP worker killed mid-run
 * cannot leak a slot permanently.
 */
final class RunSlots
{
    public function __construct(private int $slots, private int $ttlSeconds) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     *
     * @throws TooManyRunsException
     */
    public function hold(Closure $callback): mixed
    {
        $lock = $this->acquire();

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Takes a slot, to be released by the caller.
     *
     * Streaming responses need this: the slot has to be held for as long as the
     * program is producing output, which outlives the controller method that
     * started it, so a callback-shaped API cannot express it.
     *
     * @throws TooManyRunsException
     */
    public function acquire(): Lock
    {
        for ($slot = 0; $slot < $this->slots; $slot++) {
            $lock = Cache::lock("runner:slot:{$slot}", $this->ttlSeconds);

            if ($lock->get()) {
                return $lock;
            }
        }

        throw new TooManyRunsException('All run slots are busy.');
    }
}
