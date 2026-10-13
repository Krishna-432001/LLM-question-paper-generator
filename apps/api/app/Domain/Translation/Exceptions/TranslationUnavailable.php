<?php

declare(strict_types=1);

namespace App\Domain\Translation\Exceptions;

use App\Domain\Jobs\PermanentFailure;
use RuntimeException;

/**
 * A language with no translation prompt cannot be translated into, however
 * many times the job is retried. Failing permanently says so immediately
 * instead of after the whole backoff schedule.
 */
final class TranslationUnavailable extends RuntimeException implements PermanentFailure {}
