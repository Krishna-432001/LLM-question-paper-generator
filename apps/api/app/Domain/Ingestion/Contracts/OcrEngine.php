<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\Contracts;

use App\Domain\Ingestion\DTOs\ExtractedPage;

interface OcrEngine
{
    /**
     * Extract text from a rasterised document.
     *
     * @param  string  $absolutePath  local path to the source file
     * @param  list<string>  $languageHints  ISO 639-1 codes, e.g. ['ta','en']
     * @return list<ExtractedPage>
     */
    public function extract(string $absolutePath, array $languageHints = ['en']): array;

    public function name(): string;

    public function isAvailable(): bool;
}
