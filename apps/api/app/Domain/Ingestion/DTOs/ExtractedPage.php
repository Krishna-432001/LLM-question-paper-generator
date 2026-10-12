<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

final class ExtractedPage
{
    public function __construct(
        public readonly int $pageNumber,
        public readonly string $text,
        public readonly float $confidence = 1.0,
        public readonly string $engine = 'native',
    ) {}

    /**
     * A page we do not trust must not reach the chunker. Bad OCR silently
     * poisoning the question bank is the most expensive failure in the system,
     * so we would rather lose a page than trust it.
     */
    public function needsReview(): bool
    {
        return $this->confidence < (float) config('ingestion.min_page_confidence', 0.80);
    }

    public function isBlank(): bool
    {
        return mb_strlen(trim($this->text)) < 20;
    }
}
