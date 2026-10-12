<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Exceptions;

use App\Domain\Jobs\PermanentFailure;
use RuntimeException;

final class SourceQuarantinedException extends RuntimeException implements PermanentFailure
{
    public static function infected(string $sourceId, ?string $signature): self
    {
        return new self(
            "Upload {$sourceId} failed the malware scan"
            .($signature !== null ? " ({$signature})" : '')
            .' and has been quarantined. It will not be read.'
        );
    }
}
