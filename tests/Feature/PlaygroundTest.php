<?php

declare(strict_types=1);

use App\Models\Snippet;
use App\Services\Runner\CodeRunner;
use App\Services\Runner\FakeRunner;
use App\Services\Runner\RunResult;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;

/*
|--------------------------------------------------------------------------
| HTTP tests
|--------------------------------------------------------------------------
|
| These bind a FakeRunner, so no containers are involved: what is under test is
| the layer around the sandbox — validation, the concurrency gate, and the
| snippet routes. The sandbox itself is covered by RunnerTest.
|
*/

beforeEach(function (): void {
    $this->fake = new FakeRunner;
    $this->app->instance(CodeRunner::class, $this->fake);
});

it('renders an empty playground', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('playground')
            ->has('languages', 3)
            ->where('languages.0.key', 'php')
            ->where('snippet', null));
});

it('seeds the playground from a saved snippet', function (): void {
    $snippet = Snippet::create([
        'mode' => 'script',
        'language' => 'python',
        'source' => 'print("hi")',
    ]);

    $this->get("/p/{$snippet->slug}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('playground')
            ->where('snippet.source', 'print("hi")')
            ->where('snippet.language', 'python'));
});

it('runs a snippet', function (): void {
    $this->app->instance(CodeRunner::class, new FakeRunner(
        new RunResult(stdout: '4', stderr: '', exitCode: 0, durationMs: 210),
    ));

    $this->postJson('/run', ['language' => 'php', 'code' => '<?php echo 2+2;'])
        ->assertOk()
        ->assertJson(['stdout' => '4', 'exitCode' => 0, 'durationMs' => 210, 'timedOut' => false]);
});

it('passes stdin through to the runner', function (): void {
    $this->postJson('/run', ['language' => 'python', 'code' => 'x', 'stdin' => 'hello'])
        ->assertOk();

    expect($this->fake->runs[0]['stdin'])->toBe('hello');
});

it('rejects a language that is not on the allowlist', function (): void {
    $this->postJson('/run', ['language' => 'ruby', 'code' => 'puts 1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('language');

    expect($this->fake->runs)->toBeEmpty();
});

it('rejects a snippet that is too large', function (): void {
    $this->postJson('/run', ['language' => 'php', 'code' => str_repeat('a', 100_001)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');
});

it('refuses to run when every slot is busy', function (): void {
    $locks = collect(range(0, config('runner.max_concurrent') - 1))
        ->map(fn (int $slot) => tap(Cache::lock("runner:slot:{$slot}", 10))->get());

    $this->postJson('/run', ['language' => 'php', 'code' => '<?php echo 1;'])
        ->assertStatus(429)
        ->assertJsonStructure(['message']);

    expect($this->fake->runs)->toBeEmpty();

    $locks->each->release();
});

it('runs again once a slot frees up', function (): void {
    $lock = tap(Cache::lock('runner:slot:0', 10))->get();

    $this->postJson('/run', ['language' => 'php', 'code' => '<?php echo 1;'])->assertOk();

    $lock->release();
});

it('saves a script snippet and returns its link', function (): void {
    $response = $this->postJson('/snippets', [
        'mode' => 'script',
        'language' => 'php',
        'source' => '<?php echo 1;',
    ])->assertCreated();

    $slug = $response->json('slug');

    expect($slug)->toHaveLength(8)
        ->and($response->json('url'))->toEndWith("/p/{$slug}");

    $this->assertDatabaseHas('snippets', ['slug' => $slug, 'source' => '<?php echo 1;']);
});

it('saves a frontend snippet with its tailwind choice', function (): void {
    $this->postJson('/snippets', [
        'mode' => 'frontend',
        'html' => '<h1 class="text-3xl">hi</h1>',
        'css' => '',
        'js' => '',
        'tailwind' => '4',
    ])->assertCreated();

    $this->assertDatabaseHas('snippets', ['mode' => 'frontend', 'tailwind' => '4']);
});

it('mints a new slug instead of editing an existing snippet', function (): void {
    $first = $this->postJson('/snippets', ['mode' => 'script', 'language' => 'php', 'source' => 'a'])->json('slug');
    $second = $this->postJson('/snippets', ['mode' => 'script', 'language' => 'php', 'source' => 'b'])->json('slug');

    expect($first)->not->toBe($second);
    $this->assertDatabaseCount('snippets', 2);
});

it('rejects an unknown mode', function (): void {
    $this->postJson('/snippets', ['mode' => 'wat'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('mode');
});
