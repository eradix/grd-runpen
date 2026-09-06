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
    $this->runner = new PodmanRunner;
});

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
    $this->runner->run('python', 'while True: pass');

    $ps = Process::fromShellCommandline('podman ps -a --format "{{.Image}}"');
    $ps->run();

    expect($ps->getOutput())->not->toContain('python:3.14-alpine');
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
    $result = $this->runner->run('python', <<<'PY'
        while True:
            print("x" * 1000)
        PY);

    $running = Process::fromShellCommandline('podman ps --format "{{.Names}}"');
    $running->run();

    expect($result->truncated)->toBeTrue()
        ->and($running->getOutput())->not->toContain('runpen-');
});

it('explains how to pull an image that is missing', function (): void {
    config(['runner.languages.php.image' => 'docker.io/library/php:0.0-cli-alpine']);

    $result = $this->runner->run('php', '<?php echo 1;');

    expect($result->exitCode)->toBe(125)
        ->and($result->stderr)->toContain('podman pull docker.io/library/php:0.0-cli-alpine');
});

it('refuses an unknown language', function (): void {
    $this->runner->run('ruby', 'puts 1');
})->throws(InvalidArgumentException::class);

it('cleans up its run directory', function (): void {
    $this->runner->run('php', '<?php echo 1;');

    expect(glob(storage_path('app/runs/*')))->toBeEmpty();
});
