<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * `php artisan runner:prune` — deletes scratch directories left behind.
 *
 * PodmanRunner removes its own directory in a `finally`, so this only matters
 * when the PHP process itself dies mid-run (a worker killed, a fatal error, a
 * deploy). Scheduled hourly in routes/console.php so those leftovers cannot
 * accumulate unnoticed.
 */
class RunnerPrune extends Command
{
    protected $signature = 'runner:prune {--minutes=60 : Delete run directories older than this}';

    protected $description = 'Delete stale snippet scratch directories';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('minutes'))->getTimestamp();
        $pruned = 0;

        foreach (glob(storage_path('app/runs/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if (filemtime($dir) > $cutoff) {
                continue;
            }

            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }

            $pruned += (int) @rmdir($dir);
        }

        $this->components->info("Pruned {$pruned} stale run ".str('directory')->plural($pruned).'.');

        return self::SUCCESS;
    }
}
