<?php

declare(strict_types=1);

namespace App\Services\Runner;

use Closure;

/**
 * Anything that can execute a snippet and describe what happened.
 *
 * The interface exists so tests can swap in {@see FakeRunner} and exercise the
 * HTTP layer — validation, the concurrency gate, throttling — without paying
 * for a container per assertion. The real implementation is
 * {@see PodmanRunner}.
 */
interface CodeRunner
{
    /**
     * @param  string  $language  a key from config('runner.languages')
     * @param  (Closure(string, string): void)|null  $onOutput  called with ('out'|'err', chunk)
     *                                                          as the program writes, for streaming to the browser
     */
    public function run(string $language, string $code, string $stdin = '', ?Closure $onOutput = null): RunResult;
}
