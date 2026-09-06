<?php

declare(strict_types=1);

use App\Services\Runner\PodmanRunner;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Sandbox tests
|--------------------------------------------------------------------------
|
| These run real containers, so they are slower than the rest of the suite and
| need the images pulled (`php artisan runner:check`). They are the tests that
| matter most: each one asserts that a specific attack fails, and together they
| are the evidence that the flags in PodmanRunner do what its comments claim.
|
*/

beforeEach(function (): void {
    $this->runner = app(PodmanRunner::class);
});

/**
 * Containers and scratch directories are counted before and after a run rather
 * than asserted to be absent: a developer's warm pool is made of exactly the
 * same things, and a test suite has no business demanding an idle machine.
 */
function runpenContainers(): int
{
    $process = Process::fromShellCommandline('podman ps --all --filter name=^runpen- --format "{{.Names}}"');
    $process->run();

    return count(array_filter(explode("\n", trim($process->getOutput()))));
}

function runDirectories(): int
{
    return count(glob(storage_path('app/runs/*')) ?: []);
}

it('runs a snippet in each language', function (string $language, string $code, string $expected): void {
    $result = $this->runner->run($language, $code);

    expect($result->exitCode)->toBe(0)
        ->and($result->stdout)->toContain($expected)
        ->and($result->timedOut)->toBeFalse();
})->with([
    ['php', '<?php echo 2 + 2;', '4'],
    ['python', 'print(2 + 2)', '4'],
    ['node', 'console.log(2 + 2)', '4'],
]);

it('passes stdin to the program', function (): void {
    $result = $this->runner->run('python', 'import sys; print(sys.stdin.read().upper())', "hello\n");

    expect($result->stdout)->toContain('HELLO');
});

it('kills a program that never finishes', function (): void {
    $result = $this->runner->run('python', 'while True: pass');

    expect($result->timedOut)->toBeTrue()
        ->and($result->durationMs)->toBeLessThan((config('runner.timeout') + 3) * 1000);
});

it('leaves no containers behind', function (): void {
    $before = runpenContainers();

    $this->runner->run('python', 'while True: pass');

    expect(runpenContainers())->toBe($before);
});

it('blocks outbound network access', function (string $language, string $code): void {
    $result = $this->runner->run($language, $code);

    expect($result->stdout)->toContain('BLOCKED');
})->with([
    // Connect to a literal IP rather than a hostname: with no network at all,
    // a DNS lookup can hang until the run times out, which would make this test
    // pass for the wrong reason.
    ['php', '<?php echo @fsockopen("1.1.1.1", 80, $e, $s, 2) ? "REACHED" : "BLOCKED";'],
    ['python', <<<'PY'
        import socket
        try:
            socket.create_connection(("1.1.1.1", 80), timeout=2)
            print("REACHED")
        except OSError:
            print("BLOCKED")
        PY],
]);

it('stops a fork bomb at the pids limit', function (): void {
    $result = $this->runner->run('python', <<<'PY'
        import os
        spawned = 0
        try:
            while spawned < 500:
                if os.fork() == 0:
                    os._exit(0)
                spawned += 1
        except BlockingIOError:
            print("LIMITED at", spawned)
        else:
            print("UNLIMITED")
        PY);

    expect($result->stdout)->toContain('LIMITED')
        ->and($result->stdout)->not->toContain('UNLIMITED');
});

it('kills a program that exhausts memory', function (): void {
    $result = $this->runner->run('python', 'x = bytearray(400 * 1024 * 1024); print("ALLOCATED")');

    expect($result->outOfMemory)->toBeTrue()
        ->and($result->stdout)->not->toContain('ALLOCATED');
});

it('truncates runaway output', function (): void {
    $result = $this->runner->run('python', <<<'PY'
        while True:
            print("x" * 1000)
        PY);

    expect($result->truncated)->toBeTrue()
        ->and(strlen($result->stdout))->toBeLessThan(config('runner.max_output_bytes') * 2);
});

it('mounts the filesystem read-only', function (): void {
    $result = $this->runner->run('node', <<<'JS'
        const fs = require('fs');
        for (const path of ['/etc/passwd', '/code/main.js']) {
          try { fs.writeFileSync(path, 'x'); console.log('WROTE', path); }
          catch (e) { console.log('BLOCKED', path); }
        }
        JS);

    expect($result->stdout)->toContain('BLOCKED /etc/passwd')
        ->and($result->stdout)->toContain('BLOCKED /code/main.js')
        ->and($result->stdout)->not->toContain('WROTE');
});

it('can still read files inside its own image', function (): void {
    // Asserted deliberately. The container has its own /etc/passwd and can read
    // it; the isolation promise is about the *host*, and this test documents
    // exactly where that promise starts and stops.
    $result = $this->runner->run('node', 'console.log(require("fs").readFileSync("/etc/passwd").length > 0)');

    expect($result->stdout)->toContain('true');
});

it('cannot see the host filesystem', function (): void {
    $result = $this->runner->run('python', <<<'PY'
        import os
        print(sorted(os.listdir("/code")))
        PY);

    expect($result->stdout)->toContain("['main.py']");
});

it('kills the container when it truncates output', function (): void {
    // Stopping the podman client does not stop the container — conmon keeps it
    // alive — so without an explicit kill a truncated snippet would keep
    // running (and holding CPU) after its slot had been handed to someone else.
    $before = runpenContainers();

    $result = $this->runner->run('python', <<<'PY'
        while True:
            print("x" * 1000)
        PY);

    expect($result->truncated)->toBeTrue()
        ->and(runpenContainers())->toBe($before);
});

it('explains how to pull an image that is missing', function (): void {
    config(['runner.languages.php.image' => 'docker.io/library/php:0.0-cli-alpine']);

    $result = $this->runner->run('php', '<?php echo 1;');

    expect($result->exitCode)->toBe(125)
        ->and($result->stderr)->toContain('podman pull docker.io/library/php:0.0-cli-alpine');
});

it('reports peak memory for a run', function (): void {
    $result = $this->runner->run('python', 'x = bytearray(40 * 1024 * 1024); print(len(x))');

    // Read from the container's own cgroup while it is still alive. The figure
    // covers the whole container, so it is above the allocation, not equal to it.
    expect($result->peakMemoryBytes)->toBeGreaterThan(40 * 1024 * 1024)
        ->and($result->peakMemoryBytes)->toBeLessThanOrEqual(128 * 1024 * 1024);
});

it('starts its own container when the pool is empty', function (): void {
    $result = $this->runner->run('php', '<?php echo 1;');

    expect($result->warmStart)->toBeFalse()
        ->and($result->exitCode)->toBe(0);
});

it('streams output as the program produces it', function (): void {
    $chunks = [];

    $result = $this->runner->run('python', <<<'PY'
        import sys, time
        print("first", flush=True)
        time.sleep(0.4)
        print("second", flush=True)
        print("problem", file=sys.stderr, flush=True)
        PY, '', function (string $stream, string $text) use (&$chunks): void {
        $chunks[] = [$stream, trim($text)];
    });

    // Arriving in more than one chunk is the whole point: the browser shows
    // "first" while the program is still sleeping.
    expect($chunks)->not->toBeEmpty()
        ->and(collect($chunks)->where(0, 'out')->pluck(1)->implode(' '))->toContain('first')
        ->and(collect($chunks)->where(0, 'err')->pluck(1)->implode(' '))->toContain('problem')
        ->and($result->stdout)->toContain('second');
});

it('refuses an unknown language', function (): void {
    $this->runner->run('ruby', 'puts 1');
})->throws(InvalidArgumentException::class);

it('cleans up its run directory', function (): void {
    $before = runDirectories();

    $this->runner->run('php', '<?php echo 1;');

    expect(runDirectories())->toBe($before);
});
