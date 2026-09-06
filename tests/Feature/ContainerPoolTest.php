<?php

declare(strict_types=1);

use App\Services\Runner\ContainerPool;
use App\Services\Runner\PodmanRunner;

/*
|--------------------------------------------------------------------------
| Warm container pool
|--------------------------------------------------------------------------
|
| Pooling is disabled for the rest of the suite (see phpunit.xml) so that every
| other test starts its own container and cannot inherit one. These tests turn
| it on deliberately, and clean up only the containers they created.
|
*/

beforeEach(function (): void {
    // Runs replenishment immediately instead of after the response, so a test
    // never finishes with work still queued behind it.
    $this->withoutDefer();

    $this->pool = new ContainerPool(size: 1, ttl: 60, enabled: true);
});

afterEach(function (): void {
    $this->pool->flush(includeStrays: false);
});

it('starts spares and reports how many are ready', function (): void {
    expect($this->pool->size('php'))->toBe(0);

    $started = $this->pool->replenish('php', config('runner.languages.php'));

    expect($started)->toBe(1)
        ->and($this->pool->size('php'))->toBe(1);

    // Already full: replenishing again is a no-op rather than an extra container.
    expect($this->pool->replenish('php', config('runner.languages.php')))->toBe(0)
        ->and($this->pool->size('php'))->toBe(1);
});

it('runs in a warm container when one is waiting', function (): void {
    $this->pool->replenish('php', config('runner.languages.php'));

    $result = (new PodmanRunner($this->pool))->run('php', '<?php echo "warm";');

    expect($result->warmStart)->toBeTrue()
        ->and($result->stdout)->toBe('warm')
        ->and($result->exitCode)->toBe(0);
});

it('never hands out the same container twice', function (): void {
    $this->pool->replenish('php', config('runner.languages.php'));

    $spare = $this->pool->warmNames('php')[0];

    (new PodmanRunner($this->pool))->run('php', '<?php echo 1;');

    // The container that ran is destroyed and a fresh one takes its place in
    // the pool: warmth is about when the startup happens, not about reuse.
    expect($this->pool->warmNames('php'))->not->toContain($spare)
        ->and($this->pool->size('php'))->toBe(1);
});

it('does not let a snippet leak state into the next run', function (): void {
    $this->pool->replenish('python', config('runner.languages.python'));

    $runner = new PodmanRunner($this->pool);
    $runner->run('python', 'open("/tmp/left-behind", "w").write("evidence")');

    $second = $runner->run('python', 'import os; print(os.path.exists("/tmp/left-behind"))');

    expect($second->stdout)->toContain('False');
});

it('falls back to a cold start when pooling is switched off', function (): void {
    $pool = new ContainerPool(size: 1, ttl: 60, enabled: false);

    expect($pool->replenish('php', config('runner.languages.php')))->toBe(0)
        ->and((new PodmanRunner($pool))->run('php', '<?php echo 1;')->warmStart)->toBeFalse();
});
