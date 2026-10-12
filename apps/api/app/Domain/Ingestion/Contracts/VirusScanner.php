<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Contracts;

use App\Domain\Ingestion\DTOs\ScanResult;

/**
 * Malware scanning for uploaded source files.
 *
 * Every upload is user-supplied bytes that later go through pdftotext,
 * pdftoppm and tesseract — parsers with a long CVE history. A scan before any
 * of them runs is the cheapest point to stop a hostile file.
 */
interface VirusScanner
{
    public function name(): string;

    /**
     * Scan a readable local file.
     *
     * Throws when the scanner itself is unreachable, so the stage is retried
     * rather than the file being waved through unscanned.
     */
    public function scan(string $absolutePath): ScanResult;
}
