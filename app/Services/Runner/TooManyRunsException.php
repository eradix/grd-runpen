<?php

declare(strict_types=1);

namespace App\Services\Runner;

use RuntimeException;

/**
 * Thrown when every concurrency slot is taken. Rendered as a 429 so the UI can
 * say "the playground is busy" rather than showing a stack trace.
 */
final class TooManyRunsException extends RuntimeException {}
