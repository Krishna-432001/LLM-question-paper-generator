<?php

declare(strict_types=1);

namespace App\Domain\Ingestion\DTOs;

final class Chunk
{
    public function __construct(
        public readonly int $index,
        public readonly string $text,
        public readonly string $breadcrumb,
        public readonly int $tokenCount,
        public readonly ?int $pageStart = null,
        public readonly ?int $pageEnd = null,
        public readonly ?string $lessonId = null,
        public readonly string $type = 'prose',
        public readonly float $importance = 0.5,
        public readonly bool $isExercise = false,
    ) {}

    /**
     * Content-addressed: re-ingesting an edited book only re-embeds the pages
     * that actually changed, because unchanged chunks hash identically.
     */
    public function contentHash(): string
    {
        return hash('sha256', $this->text);
    }
}
