<?php

declare(strict_types=1);

namespace App\Infrastructure\Antivirus;

use App\Domain\Ingestion\Contracts\VirusScanner;
use App\Domain\Ingestion\DTOs\ScanResult;

/**
 * Offline stand-in that flags one project-specific marker, so the quarantine
 * path can be tested without a clamd daemon.
 *
 * Deliberately not the EICAR string: the host's own antivirus (Windows
 * Defender on a dev machine, the runner's on CI) blocks reads of an EICAR
 * file, so the test would exercise the host's scanner instead of this code.
 */
final class FakeVirusScanner implements VirusScanner
{
    public const MARKER = 'EXAMFORGE-FAKE-MALWARE-MARKER';

    public function name(): string
    {
        return 'fake';
    }

    public function scan(string $absolutePath): ScanResult
    {
        $contents = (string) file_get_contents($absolutePath);

        if (str_contains($contents, self::MARKER)) {
            return new ScanResult(clean: false, engine: $this->name(), signature: 'ExamForge.Test.Marker');
        }

        return new ScanResult(clean: true, engine: $this->name());
    }
}
