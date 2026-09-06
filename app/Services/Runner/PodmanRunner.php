<?php

declare(strict_types=1);

namespace App\Services\Runner;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a snippet inside a throwaway rootless Podman container.
 *
 * The whole point of this class is that the code it executes is hostile until
 * proven otherwise. Every flag in buildCommand() is there to take something
 * away from it; the README explains each one in prose. The short version:
 *
 *   --network=none        it cannot phone home, scan the LAN, or exfiltrate
 *   --read-only           it cannot modify the filesystem, including its own image
 *   --tmpfs /tmp noexec   it gets scratch space it cannot execute from
 *   --memory              allocation bombs are OOM-killed instead of swapping the host
 *   --pids-limit          fork bombs hit a wall at 64 processes
 *   --cap-drop=ALL        no capabilities, even the ones root normally keeps
 *   --user 65534          it runs as nobody, not as root inside the namespace
 *   --timeout             conmon kills the container, so ignoring SIGTERM does not help
 *
 * What this does NOT protect against: the container shares the host kernel, so a
 * kernel exploit escapes it. Strong boundary against sloppy or greedy code, weak
 * one against a determined attacker.
 */
final class PodmanRunner implements CodeRunner
{
    public function run(string $language, string $code, string $stdin = ''): RunResult
    {
        $config = $this->language($language);

        // The snippet is written to a file and mounted, never passed as an
        // argument. Nothing user-supplied is ever interpolated into the command
        // line — Symfony's array form means there is no shell to escape for
        // either, so the usual quoting bugs cannot exist here.
        $dir = $this->makeRunDirectory();

        try {
            $path = $dir.'/'.$config['filename'];
            file_put_contents($path, $code);

            // 0755 on the directory and 0644 on the file: the container runs as
            // uid 65534, which is a different (sub)uid from ours, so it needs
            // world-execute on the directory to traverse it and world-read on
            // the file to open it.
            chmod($dir, 0755);
            chmod($path, 0644);

            return $this->execute($config, $dir, $stdin);
        } finally {
            $this->deleteDirectory($dir);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function execute(array $config, string $dir, string $stdin): RunResult
    {
        $timeout = (int) config('runner.timeout');
        $limit = (int) config('runner.max_output_bytes');

        // The container is named so it can be killed by name. Killing the
        // `podman run` client does NOT kill the container — conmon keeps it
        // alive — so stopping the process alone would leave a snippet running
        // (and holding CPU) after we have already handed its slot to someone
        // else.
        $name = 'runpen-'.basename($dir);

        $process = new Process($this->buildCommand($config, $dir, $stdin !== '', $name));

        // Backstop only. The real timeout is podman's, which kills the container;
        // this one catches a podman client that itself hangs, so it is given a
        // couple of seconds of slack to let the container-level kill win.
        $process->setTimeout($timeout + 3);

        if ($stdin !== '') {
            $process->setInput($stdin);
        }

        $stdout = '';
        $stderr = '';
        $truncated = false;

        $startedAt = hrtime(true);

        // The output cap has to be enforced while the process runs, not after:
        // `while true: print("x")` would otherwise stream until PHP itself runs
        // out of memory. The collector only accumulates and raises a flag —
        // stopping the process from inside Symfony's read callback corrupts its
        // internal state, so the kill happens in the poll loop below.
        $collect = function (string $type, string $buffer) use (&$stdout, &$stderr, &$truncated, $limit): void {
            if ($type === Process::OUT) {
                $stdout .= $buffer;
            } else {
                $stderr .= $buffer;
            }

            $truncated = $truncated || strlen($stdout) > $limit || strlen($stderr) > $limit;
        };

        try {
            $process->start($collect);

            while ($process->isRunning()) {
                // Backstop for a hung podman client; the container's own
                // --timeout normally fires first.
                $process->checkTimeout();

                if ($truncated) {
                    // Order matters: stop the client first. While `podman run`
                    // is attached and blocked on a full output pipe it holds the
                    // container's lock, so a `podman kill` issued first waits on
                    // that lock instead of killing anything.
                    $process->stop(0);
                    $this->kill($name);

                    break;
                }

                usleep(20_000);
            }

            if (! $truncated) {
                $process->wait();
            }
        } catch (ProcessTimedOutException) {
            $process->stop(0);
            $this->kill($name);

            return new RunResult(
                stdout: $this->cap($stdout, $limit),
                stderr: trim($stderr) ?: 'The runner did not respond in time.',
                exitCode: null,
                durationMs: $this->elapsedMs($startedAt),
                timedOut: true,
                truncated: $truncated,
            );
        }

        $durationMs = $this->elapsedMs($startedAt);
        $exitCode = $process->getExitCode();

        // podman exits 255 when it kills the container on --timeout, and the
        // container exits 137 (128 + SIGKILL) when the OOM killer takes it.
        // Both look like ordinary failures unless we name them, and "exit 137"
        // is not a useful thing to show someone who just wrote a loop.
        $timedOut = ! $truncated && $exitCode === 255 && $durationMs >= ($timeout * 1000) - 250;
        $outOfMemory = ! $truncated && $exitCode === 137;

        // 125 is podman itself refusing to start, not the snippet failing.
        if ($exitCode === 125) {
            $stderr = $this->describeStartupFailure($stderr, (string) $config['image']);
        }

        return new RunResult(
            stdout: $this->cap($stdout, $limit),
            stderr: $this->cap($stderr, $limit),
            exitCode: $exitCode,
            durationMs: $durationMs,
            timedOut: $timedOut,
            truncated: $truncated,
            outOfMemory: $outOfMemory,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function buildCommand(array $config, string $dir, bool $withStdin, string $name): array
    {
        $command = [
            config('runner.podman'),
            'run',
            '--rm',                                 // no container survives its run
            '--name='.$name,                        // so it can be killed by name, see execute()
            '--pull=never',                         // never fetch inside a request
            '--timeout='.config('runner.timeout'),  // conmon kills it, not us
            '--network=none',
            '--read-only',
            '--tmpfs', '/tmp:size=8m,noexec,nosuid',
            '--memory='.config('runner.memory'),
            '--memory-swap='.config('runner.memory'), // equal to --memory means: no swap
            '--cpus='.config('runner.cpus'),
            '--pids-limit='.config('runner.pids'),
            '--cap-drop=ALL',
            '--security-opt=no-new-privileges',
            '--user=65534:65534',
            '--workdir=/code',
            // Mounted at /code, not /run: podman puts its own bind mounts under
            // /run (/run/secrets, /run/.containerenv), so mounting there leaves
            // the snippet looking at a directory it does not fully own.
            '--volume', $dir.':/code:ro,z',         // z relabels for SELinux (Fedora)
        ];

        if ($withStdin) {
            $command[] = '--interactive';
        }

        $command[] = (string) $config['image'];

        foreach ((array) $config['command'] as $argument) {
            $command[] = (string) $argument;
        }

        return $command;
    }

    /**
     * @return array<string, mixed>
     */
    private function language(string $language): array
    {
        $config = config('runner.languages.'.$language);

        // Belt and braces: the request is validated against the same list, but
        // this class must be safe to call from anywhere, including a console
        // command or a future queue job.
        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown language [{$language}].");
        }

        return $config;
    }

    /**
     * Kills the container directly. `podman kill` on a container that has
     * already exited is a normal outcome of the race between this and the
     * container finishing on its own, so its failure is not interesting.
     */
    private function kill(string $name): void
    {
        $kill = new Process([config('runner.podman'), 'kill', $name]);
        $kill->setTimeout(5);

        try {
            $kill->run();
        } catch (ProcessTimedOutException) {
            // Nothing useful left to do: the container has its own --timeout,
            // so it dies on its own shortly after. Never let cleanup turn into
            // the thing that fails the request.
        }
    }

    /**
     * podman exits 125 before the snippet ever starts — most often because the
     * image has not been pulled, which is the single most common way a fresh
     * clone fails. "image not known" means nothing to someone who has just
     * cloned this; the pull command does.
     */
    private function describeStartupFailure(string $stderr, string $image): string
    {
        if (str_contains($stderr, 'image not known') || str_contains($stderr, 'no such image')) {
            return "The {$image} image has not been pulled yet. Run:\n\n    podman pull {$image}\n\nthen `php artisan runner:check`.";
        }

        return trim($stderr) ?: 'The container could not be started.';
    }

    private function makeRunDirectory(): string
    {
        $dir = storage_path('app/runs/'.bin2hex(random_bytes(8)));

        if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create run directory [{$dir}].");
        }

        return $dir;
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    private function cap(string $output, int $limit): string
    {
        if (strlen($output) <= $limit) {
            return $output;
        }

        return substr($output, 0, $limit)."\n\n[output truncated at ".number_format($limit).' bytes]';
    }

    private function elapsedMs(float|int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
