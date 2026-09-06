<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Runner\CodeRunner;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * `php artisan runner:check` — confirms the machine can actually run snippets.
 *
 * This is the first thing to run after cloning: it reports whether podman is
 * installed, whether each image has been pulled, and whether a sample snippet
 * really executes inside each one.
 */
class RunnerCheck extends Command
{
    protected $signature = 'runner:check';

    protected $description = 'Verify podman and the runtime images are ready';

    public function handle(CodeRunner $runner): int
    {
        $podman = new Process([config('runner.podman'), '--version']);
        $podman->run();

        if (! $podman->isSuccessful()) {
            $this->error('podman not found. Install it, then re-run this command.');

            return self::FAILURE;
        }

        $this->line(trim($podman->getOutput()));
        $this->newLine();

        $failed = false;

        foreach (config('runner.languages') as $key => $config) {
            $exists = new Process([config('runner.podman'), 'image', 'exists', $config['image']]);
            $exists->run();

            if (! $exists->isSuccessful()) {
                $this->components->error("{$config['label']}: image missing — podman pull {$config['image']}");
                $failed = true;

                continue;
            }

            $result = $runner->run($key, $config['sample']);

            $result->exitCode === 0
                ? $this->components->info("{$config['label']} {$config['version']}: ok ({$result->durationMs}ms)")
                : $this->components->error("{$config['label']}: sample failed — ".trim($result->stderr));

            $failed = $failed || $result->exitCode !== 0;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
