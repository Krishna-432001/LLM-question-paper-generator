<?php

declare(strict_types=1);

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

/**
 * Thrown before a single token is spent. Surfaces as HTTP 402 with the quota,
 * usage and upgrade path attached.
 */
class BudgetExceededException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $metric,
        public readonly int $limit,
        public readonly int $used,
        public readonly ?string $resetsAt = null,
    ) {
        parent::__construct($message);
    }
}
