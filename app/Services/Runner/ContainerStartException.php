<?php

declare(strict_types=1);

namespace App\Services\Runner;

use RuntimeException;

/**
 * podman refused to start a container — nearly always an image that has not
 * been pulled. Carries podman's own stderr so the message can be translated
 * into something actionable.
 */
final class ContainerStartException extends RuntimeException
{
    public function __construct(public readonly string $stderr, public readonly string $image)
    {
        parent::__construct("Could not start a container from {$image}.");
    }
}
