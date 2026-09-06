<?php

declare(strict_types=1);

namespace App\Services\Runner;

/**
 * One container, started and waiting (or started just now), plus the scratch
 * directory bind-mounted into it at /code.
 *
 * The directory is mounted when the container starts, but written *after* — a
 * bind mount is live, so code placed there by the host is visible inside
 * immediately. That is what lets a container be started before anyone has
 * written the snippet it will run.
 */
final readonly class PooledContainer
{
    public function __construct(
        public string $language,
        public string $name,
        public string $id,
        public string $directory,
        public bool $wasWarm,
    ) {}

    /** @return array{language: string, name: string, id: string, directory: string} */
    public function toArray(): array
    {
        return [
            'language' => $this->language,
            'name' => $this->name,
            'id' => $this->id,
            'directory' => $this->directory,
        ];
    }
}
