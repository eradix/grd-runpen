<?php

declare(strict_types=1);

namespace App\Services\Runner;

use Closure;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs a snippet inside a rootless Podman container that is used once and
 * thrown away.
 *
 * The whole point of this class is that the code it executes is hostile until
 * proven otherwise. Every flag in ContainerPool::start() is there to take
 * something away from it; the README explains each one in prose. The short
 * version:
 *
 *   --network=none        it cannot phone home, scan the LAN, or exfiltrate
 *   --read-only           it cannot modify the filesystem, including its own image
 *   --tmpfs /tmp noexec   it gets scratch space it cannot execute from
 *   --memory              allocation bombs are OOM-killed instead of swapping the host
 *   --pids-limit          fork bombs hit a wall at 64 processes
 *   --cap-drop=ALL        no capabilities, even the ones root normally keeps
 *   --user 65534          it runs as nobody, not as root inside the namespace
 *
 * What this does NOT protect against: the container shares the host kernel, so
 * a kernel exploit escapes it. Strong boundary against sloppy or greedy code,
 * weak one against a determined attacker.
 *
 * The container comes from {@see ContainerPool}, which usually has one started
 * and waiting. Because a container is still used for exactly one run, warmth
 * buys latency and costs nothing in isolation.
 */
final class PodmanRunner implements CodeRunner
{
    public function __construct(private ContainerPool $pool) {}

    public function run(string $language, string $code, string $stdin = '', ?Closure $onOutput = null): RunResult
    {
        $config = $this->language($language);

        $result = $this->attempt($language, $config, $code, $stdin, $onOutput, allowWarm: true);

        // A pooled container is claimed without asking podman whether it is
        // still there, because checking costs as much as the run. The price of
        // that shortcut is this: if the container turned out to be gone, the
        // run is repeated on one started here and now.
        if ($this->containerVanished($result)) {
            return $this->attempt($language, $config, $code, $stdin, $onOutput, allowWarm: false);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  (Closure(string, string): void)|null  $onOutput
     */
    private function attempt(string $language, array $config, string $code, string $stdin, ?Closure $onOutput, bool $allowWarm): RunResult
    {
        try {
            $container = $this->pool->acquire($language, $config, $allowWarm);
        } catch (ContainerStartException $exception) {
            // Nothing ran, so there is no exit code of the program's own; 125 is
            // podman's own "could not start" code, kept here so callers can tell
            // this apart from a snippet that failed.
            return new RunResult(
                stdout: '',
                stderr: $this->describeStartupFailure($exception),
                exitCode: 125,
                durationMs: 0,
            );
        }

        try {
            // The snippet is written into the directory already mounted at
            // /code, never passed as an argument. Nothing user-supplied reaches
            // the command line — Symfony's array form means there is no shell to
            // escape for either, so the usual quoting bugs cannot exist here.
            $path = $container->directory.'/'.$config['filename'];

            file_put_contents($path, $code);

            // World-readable because the container runs as uid 65534, which is a
            // different (sub)uid from ours.
            chmod($path, 0644);

            return $this->execute($container, $config, $stdin, $onOutput);
        } finally {
            $cleanup = function () use ($container, $language, $config): void {
                $this->pool->release($container);
                $this->pool->replenish($language, $config);
            };

            // In a request both steps happen after the response is flushed:
            // `podman rm` costs about as much as `podman run`, so tearing down
            // in-request would hand back exactly what the warm pool saved — the
            // visitor would wait for their container to be destroyed and the
            // next one built before seeing their own output.
            //
            // On the command line there is no response to hide behind, and a
            // long-lived process (the test suite, an artisan command) would
            // otherwise keep every container it has ever used alive until it
            // exits. So there, clean up immediately.
            app()->runningInConsole() ? $cleanup() : defer($cleanup);
        }
    }

    /** podman could not exec because the container was no longer there. */
    private function containerVanished(RunResult $result): bool
    {
        return $result->exitCode === 125
            && (str_contains($result->stderr, 'no such container') || str_contains($result->stderr, 'is not running'));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  (Closure(string, string): void)|null  $onOutput
     */
    private function execute(PooledContainer $container, array $config, string $stdin, ?Closure $onOutput): RunResult
    {
        $timeout = (int) config('runner.timeout');
        $limit = (int) config('runner.max_output_bytes');
        $memory = new CgroupMemory($container->id);

        $process = new Process($this->buildCommand($container, $config, $stdin !== ''));

        // Backstop only. The authority on the deadline is the elapsed-time check
        // in the poll loop, which also kills the container; this catches a
        // podman client that itself stops responding.
        $process->setTimeout($timeout + 3);

        if ($stdin !== '') {
            $process->setInput($stdin);
        }

        $stdout = '';
        $stderr = '';
        $truncated = false;
        $peak = null;

        $startedAt = hrtime(true);

        // Output is handled as it arrives rather than at the end: it is what
        // lets the browser show a long-running program's output as it happens,
        // and it is what makes the size cap real — `while True: print("x")`
        // would otherwise stream until PHP itself ran out of memory.
        $collect = function (string $type, string $buffer) use (&$stdout, &$stderr, &$truncated, $limit, $onOutput): void {
            $stream = $type === Process::OUT ? 'out' : 'err';

            if ($stream === 'out') {
                $stdout .= $buffer;
            } else {
                $stderr .= $buffer;
            }

            if ($truncated) {
                return;
            }

            if ($onOutput !== null) {
                $onOutput($stream, $buffer);
            }

            $truncated = strlen($stdout) > $limit || strlen($stderr) > $limit;
        };

        $timedOut = false;

        try {
            $process->start($collect);

            while ($process->isRunning()) {
                $process->checkTimeout();

                $peak = $memory->peak() ?? $peak;
                $elapsed = $this->elapsedMs($startedAt);

                if ($truncated || $elapsed >= $timeout * 1000) {
                    $timedOut = ! $truncated;

                    // Order matters: stop the client first. While `podman exec`
                    // is attached and blocked on a full output pipe it holds the
                    // container's lock, so a kill issued first would wait on
                    // that lock instead of killing anything.
                    $process->stop(0);
                    $this->kill($container->name);

                    break;
                }

                usleep(20_000);
            }

            if (! $truncated && ! $timedOut) {
                $process->wait();
            }
        } catch (ProcessTimedOutException) {
            $process->stop(0);
            $this->kill($container->name);

            $timedOut = true;
        }

        $durationMs = $this->elapsedMs($startedAt);

        // Read once more now the program has stopped but the container is still
        // alive: memory.peak only exists until the container is removed.
        $peak = $memory->peak() ?? $peak;
        $outOfMemory = $memory->oomKills() > 0;

        return new RunResult(
            stdout: $this->cap($stdout, $limit),
            stderr: $this->cap($stderr, $limit),
            exitCode: $timedOut ? null : $process->getExitCode(),
            durationMs: $durationMs,
            timedOut: $timedOut,
            truncated: $truncated,
            outOfMemory: $outOfMemory,
            peakMemoryBytes: $peak,
            warmStart: $container->wasWarm,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function buildCommand(PooledContainer $container, array $config, bool $withStdin): array
    {
        $command = [(string) config('runner.podman'), 'exec'];

        if ($withStdin) {
            $command[] = '--interactive';
        }

        $command[] = $container->name;

        foreach ((array) $config['command'] as $argument) {
            $command[] = (string) $argument;
        }

        return $command;
    }

    /**
     * Kills the container, and with it whatever the snippet started. `podman
     * kill` on a container that has already exited is a normal outcome of the
     * race with the program finishing on its own, so failures are ignored —
     * cleanup must never be the thing that fails a request.
     */
    private function kill(string $name): void
    {
        $kill = new Process([config('runner.podman'), 'kill', $name]);
        $kill->setTimeout(5);

        try {
            $kill->run();
        } catch (ProcessTimedOutException) {
            // The container is removed moments later regardless.
        }
    }

    /**
     * podman refuses to start most often because the image has not been
     * pulled, which is how a fresh clone fails. "image not known" means nothing
     * to someone who has just cloned this; the pull command does.
     */
    private function describeStartupFailure(ContainerStartException $exception): string
    {
        $stderr = $exception->stderr;

        if (str_contains($stderr, 'image not known') || str_contains($stderr, 'no such image')) {
            return "The {$exception->image} image has not been pulled yet. Run:\n\n    podman pull {$exception->image}\n\nthen `php artisan runner:check`.";
        }

        return trim($stderr) ?: 'The container could not be started.';
    }

    /**
     * @return array<string, mixed>
     */
    private function language(string $language): array
    {
        $config = config('runner.languages.'.$language);

        // Belt and braces: requests are validated against the same list, but
        // this class must be safe to call from anywhere, including a console
        // command or a future queue job.
        if (! is_array($config)) {
            throw new InvalidArgumentException("Unknown language [{$language}].");
        }

        return $config;
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
