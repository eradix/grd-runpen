<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Runner\ContainerPool;
use Illuminate\Console\Command;

/**
 * `php artisan runner:warm` — fills the pool of ready containers.
 *
 * Runs top the pool up themselves after each response, so this is for the
 * moments when nothing has run yet: after a deploy, a restart, or a cache
 * flush. Without it the first visitor of the day pays the ~200ms of container
 * startup that everyone after them avoids.
 *
 * `--flush` throws away the current pool first, which is how to pick up a
 * changed image or limit.
 */
class RunnerWarm extends Command
{
    protected $signature = 'runner:warm {--flush : Remove existing containers before warming}';

    protected $description = 'Start the pool of ready containers';

    public function handle(ContainerPool $pool): int
    {
        if (! config('runner.pool.enabled')) {
            $this->components->warn('The container pool is disabled (RUNNER_POOL=false). Nothing to warm.');

            return self::SUCCESS;
        }

        if ($this->option('flush')) {
            $this->components->info('Removed '.$pool->flush().' existing containers.');
        }

        foreach (config('runner.languages') as $key => $config) {
            $started = $pool->replenish((string) $key, $config);
            $size = $pool->size((string) $key);

            $started > 0 || $size > 0
                ? $this->components->info("{$config['label']} {$config['version']}: {$size} ready (started {$started}).")
                : $this->components->error("{$config['label']}: could not start a container — try `php artisan runner:check`.");
        }

        return self::SUCCESS;
    }
}
