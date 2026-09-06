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

it('streams a run back as server-sent events', function (): void {
    $this->app->instance(CodeRunner::class, new FakeRunner(
        new RunResult(stdout: '4', stderr: '', exitCode: 0, durationMs: 210, peakMemoryBytes: 12_582_912),
    ));

    $response = $this->postJson('/run', ['language' => 'php', 'code' => '<?php echo 2+2;'])
        ->assertOk()
        ->assertHeader('content-type', 'text/event-stream; charset=utf-8');

    $stream = $response->streamedContent();

    // Output arrives as it is produced, then one final event carries the
    // verdict — exit code, duration, and the flags the UI turns into badges.
    expect($stream)->toContain('event: output')
        ->and($stream)->toContain('"stream":"out","text":"4"')
        ->and($stream)->toContain('event: done')
        ->and($stream)->toContain('"exitCode":0')
        ->and($stream)->toContain('"peakMemoryBytes":12582912');
});

it('reports stderr on its own stream', function (): void {
    $this->app->instance(CodeRunner::class, new FakeRunner(
        new RunResult(stdout: '', stderr: 'Fatal error', exitCode: 255, durationMs: 30),
    ));

    expect($this->postJson('/run', ['language' => 'php', 'code' => 'x'])->streamedContent())
        ->toContain('"stream":"err","text":"Fatal error"');
});

it('passes stdin through to the runner', function (): void {
    $this->postJson('/run', ['language' => 'python', 'code' => 'x', 'stdin' => 'hello'])
        ->assertOk()
        ->streamedContent();

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

it('releases its slot when the stream finishes', function (): void {
    // The slot is held across the whole stream rather than the controller
    // method, so a bug here would wedge the playground after N runs.
    foreach (range(1, config('runner.max_concurrent') + 2) as $ignored) {
        $this->postJson('/run', ['language' => 'php', 'code' => '<?php echo 1;'])
            ->assertOk()
            ->streamedContent();
    }

    expect($this->fake->runs)->toHaveCount(config('runner.max_concurrent') + 2);
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

it('lists recently shared snippets', function (): void {
    Snippet::create(['mode' => 'script', 'language' => 'php', 'source' => "<?php\n\necho 'hello';"]);
    Snippet::create(['mode' => 'frontend', 'html' => '<h1>a heading</h1>']);

    $this->get('/recent')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('recent')
            ->has('snippets', 2)
            // Newest first, and the preview skips the opening PHP tag.
            ->where('snippets.0.label', 'Frontend')
            ->where('snippets.0.preview', '<h1>a heading</h1>')
            ->where('snippets.1.label', 'PHP')
            ->where('snippets.1.preview', "echo 'hello';"));
});

it('shows an empty recent page when nothing has been shared', function (): void {
    $this->get('/recent')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('recent')->has('snippets', 0));
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
