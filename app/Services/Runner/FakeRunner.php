<?php

declare(strict_types=1);

namespace App\Services\Runner;

/**
 * A runner that never touches Podman.
 *
 * Bound in place of {@see PodmanRunner} for HTTP tests, so route validation, the
 * concurrency gate and throttling can be exercised in milliseconds. The sandbox
 * itself is tested separately, against real containers.
 */
final class FakeRunner implements CodeRunner
{
    /** @var list<array{language: string, code: string, stdin: string}> */
    public array $runs = [];

    public function __construct(private RunResult $result = new RunResult('ok', '', 0, 12)) {}

    public function run(string $language, string $code, string $stdin = ''): RunResult
    {
        $this->runs[] = compact('language', 'code', 'stdin');

        return $this->result;
    }
}
