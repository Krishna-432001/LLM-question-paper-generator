<?php

declare(strict_types=1);

namespace App\Infrastructure\Antivirus;

use App\Domain\Ingestion\Contracts\VirusScanner;
use App\Domain\Ingestion\DTOs\ScanResult;

/**
 * No scanner configured. The result says "not_scanned" rather than "clean",
 * so the pipeline state records honestly that nothing looked at the file.
 *
 * Refused in production by IngestionServiceProvider: an unscanned upload
 * reaching pdftotext there is exactly the hole this stage exists to close.
 */
final class NullVirusScanner implements VirusScanner
{
    public function name(): string
    {
        return 'none';
    }

    public function scan(string $absolutePath): ScanResult
    {
        return new ScanResult(clean: true, engine: $this->name(), scanned: false);
    }
}
