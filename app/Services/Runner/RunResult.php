<?php

declare(strict_types=1);

namespace App\Services\Runner;

/**
 * The outcome of one snippet execution.
 *
 * Deliberately dumb: no behaviour beyond describing what happened, so the HTTP
 * layer and the React UI can share one shape. `timedOut` and `truncated` are
 * separate from `exitCode` because both are *our* verdicts about the run rather
 * than the program's own, and the UI phrases them differently.
 */
final readonly class RunResult
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public ?int $exitCode,
        public int $durationMs,
        public bool $timedOut = false,
        public bool $truncated = false,
        public bool $outOfMemory = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'stdout' => $this->stdout,
            'stderr' => $this->stderr,
            'exitCode' => $this->exitCode,
            'durationMs' => $this->durationMs,
            'timedOut' => $this->timedOut,
            'truncated' => $this->truncated,
            'outOfMemory' => $this->outOfMemory,
        ];
    }
}
