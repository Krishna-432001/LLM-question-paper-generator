<?php

declare(strict_types=1);

namespace App\Domain\Jobs;

enum JobStatus: string
{
    case Queued = 'QUEUED';
    case Processing = 'PROCESSING';
    case Validating = 'VALIDATING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
    case RetryScheduled = 'RETRY_SCHEDULED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }

    public function isRunning(): bool
    {
        return in_array($this, [self::Processing, self::Validating], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Validating => 'Validating',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
            self::RetryScheduled => 'Retry scheduled',
        };
    }
}
