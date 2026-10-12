<?php

declare(strict_types=1);

namespace App\Domain\Jobs;

/**
 * Marks an exception that no retry can fix — an infected upload, a file that
 * is not a PDF. TrackedJob fails these immediately instead of spending the
 * backoff schedule (and the queue's time) re-running the same verdict.
 */
interface PermanentFailure {}
