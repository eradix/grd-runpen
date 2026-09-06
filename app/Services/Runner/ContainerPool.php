<?php

declare(strict_types=1);

namespace App\Services\Runner;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Keeps a few containers started and waiting, so a run does not pay for
 * `podman run`.
 *
 * A container is handed out for exactly one run and destroyed afterwards, with
 * a replacement started after the response has been sent. Runs therefore still
 * get a container nobody else has touched — the ~200ms of startup simply
 * happens outside the request instead of inside it.
 *
 * The registry lives in the cache rather than in memory because every PHP
 * worker has its own memory but they all share one machine; a spare started
 * while serving one request must be visible to the next. Pops are taken under
 * a lock so two requests cannot claim the same container.
 */
final class ContainerPool
{
    public function __construct(private int $size, private int $ttl, private bool $enabled) {}

    /**
     * A container ready to run this language, warm if one was waiting.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ContainerStartException
     */
    public function acquire(string $language, array $config, bool $allowWarm = true): PooledContainer
    {
        if ($allowWarm && $this->enabled && ($warm = $this->pop($language)) !== null) {
            return $warm;
        }

        return $this->start($language, $config, warm: false);
    }

    /**
     * Destroys a used container and its scratch directory.
     *
     * Called in a `finally`, so it must not throw: a failure to clean up is
     * worth logging, never worth turning a successful run into an error.
     */
    public function release(PooledContainer $container): void
    {
        $this->podman(['rm', '--force', '--time=0', $container->name], timeout: 15);

        foreach (glob($container->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($container->directory);
    }

    /**
     * Tops the pool up to its configured size. Returns how many were started.
     *
     * @param  array<string, mixed>  $config
     */
    public function replenish(string $language, array $config): int
    {
        if (! $this->enabled) {
            return 0;
        }

        $started = 0;

        while ($this->size($language) < $this->size) {
            try {
                $this->push($this->start($language, $config, warm: true));
                $started++;
            } catch (ContainerStartException) {
                // An image that cannot start is reported when someone actually
                // runs something; failing to pre-warm is not worth an error.
                break;
            }
        }

        return $started;
    }

    /** How many spares are currently registered for a language. */
    public function size(string $language): int
    {
        return count($this->registry($language));
    }

    /**
     * The names of the spares currently waiting.
     *
     * Useful for diagnostics, and it is how the tests prove a container is
     * never handed out twice: the name that was waiting must be gone after a
     * run, replaced by a different one.
     *
     * @return list<string>
     */
    public function warmNames(string $language): array
    {
        return array_column($this->registry($language), 'name');
    }

    /**
     * Removes every pooled container.
     *
     * `$includeStrays` also sweeps up runpen containers the registry has
     * forgotten — the residue of a PHP process that died mid-run. Tests pass
     * false so that running the suite does not empty a developer's pool.
     */
    public function flush(bool $includeStrays = true): int
    {
        $removed = 0;

        foreach (array_keys((array) config('runner.languages')) as $language) {
            while (($container = $this->pop((string) $language)) !== null) {
                $this->release($container);
                $removed++;
            }
        }

        if (! $includeStrays) {
            return $removed;
        }

        $list = $this->podman(['ps', '--all', '--filter', 'name=^runpen-', '--format', '{{.Names}}'], timeout: 15);

        foreach (preg_split('/\s+/', trim($list->getOutput())) ?: [] as $name) {
            if ($name !== '') {
                $this->podman(['rm', '--force', '--time=0', $name], timeout: 15);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Starts one container running `sleep infinity`, with the snippet's
     * eventual scratch directory already mounted read-only at /code.
     *
     * The security flags are the same ones a one-shot run would use; see
     * PodmanRunner for what each of them stops. The one difference is
     * --timeout, which here bounds the container's *whole life* rather than a
     * single run, so a spare nobody claims cleans itself up.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws ContainerStartException
     */
    private function start(string $language, array $config, bool $warm): PooledContainer
    {
        $token = bin2hex(random_bytes(8));
        $name = 'runpen-'.$token;
        $directory = storage_path('app/runs/'.$token);

        if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create run directory [{$directory}].");
        }

        // 0755 so uid 65534 inside the container can traverse it; the snippet
        // file written into it later gets 0644 for the same reason.
        chmod($directory, 0755);

        $process = $this->podman([
            'run',
            '--detach',
            '--name='.$name,
            '--pull=never',
            '--timeout='.$this->ttl,
            '--network=none',
            '--read-only',
            '--tmpfs', '/tmp:size=8m,noexec,nosuid',
            '--memory='.config('runner.memory'),
            '--memory-swap='.config('runner.memory'),
            '--cpus='.config('runner.cpus'),
            '--pids-limit='.config('runner.pids'),
            '--cap-drop=ALL',
            '--security-opt=no-new-privileges',
            '--user=65534:65534',
            '--workdir=/code',
            '--volume', $directory.':/code:ro,z',
            (string) $config['image'],
            'sleep', 'infinity',
        ], timeout: 60);

        if (! $process->isSuccessful()) {
            @rmdir($directory);

            throw new ContainerStartException($process->getErrorOutput(), (string) $config['image']);
        }

        return new PooledContainer(
            language: $language,
            name: $name,
            id: trim($process->getOutput()),
            directory: $directory,
            wasWarm: $warm,
        );
    }

    /**
     * Takes a spare, skipping any that podman will have removed on its own
     * --timeout by now.
     *
     * Expiry is judged from the registry rather than by asking podman, because
     * `podman container inspect` costs ~80ms — as much as the exec that follows
     * it, which would give back most of what the pool exists to save. If a
     * container turns out to be gone anyway, PodmanRunner falls back to a cold
     * start; being wrong is cheap, and asking every time is not.
     */
    private function pop(string $language): ?PooledContainer
    {
        $lock = Cache::lock("runner:pool:{$language}:lock", 10);

        if (! $lock->get()) {
            return null;
        }

        try {
            $registry = $this->registry($language);

            while ($entry = array_shift($registry)) {
                $this->write($language, $registry);

                $container = new PooledContainer(
                    language: $language,
                    name: $entry['name'],
                    id: $entry['id'],
                    directory: $entry['directory'],
                    wasWarm: true,
                );

                if ($entry['expires'] > time()) {
                    return $container;
                }

                // Past its life; podman has removed it or is about to.
                $this->release($container);
            }

            return null;
        } finally {
            $lock->release();
        }
    }

    private function push(PooledContainer $container): void
    {
        $lock = Cache::lock("runner:pool:{$container->language}:lock", 10);

        if (! $lock->get()) {
            // Someone else is rearranging the pool; rather than wait, discard
            // this spare so it cannot become an untracked container.
            $this->release($container);

            return;
        }

        try {
            $registry = $this->registry($container->language);

            // A margin under the container's own --timeout, so a spare is never
            // handed out in the moments before podman removes it.
            $registry[] = [...$container->toArray(), 'expires' => time() + $this->ttl - 10];

            $this->write($container->language, $registry);
        } finally {
            $lock->release();
        }
    }

    /** @return list<array{language: string, name: string, id: string, directory: string, expires: int}> */
    private function registry(string $language): array
    {
        return Cache::get("runner:pool:{$language}", []);
    }

    /** @param  list<array{language: string, name: string, id: string, directory: string, expires: int}>  $registry */
    private function write(string $language, array $registry): void
    {
        Cache::put("runner:pool:{$language}", $registry);
    }

    /** @param  list<string>  $arguments */
    private function podman(array $arguments, int $timeout): Process
    {
        $process = new Process([config('runner.podman'), ...$arguments]);
        $process->setTimeout($timeout);
        $process->run();

        return $process;
    }
}
