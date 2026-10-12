<?php

declare(strict_types=1);

namespace App\Domain\Ai\DTOs;

final class LlmResponse
{
    /** @param  array<string, mixed>  $data */
    public function __construct(
        public readonly array $data,
        public readonly string $modelKey,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly int $cachedInputTokens = 0,
        public readonly int $latencyMs = 0,
        public readonly ?string $finishReason = null,
        public readonly bool $degraded = false,
        public readonly bool $cacheHit = false,
        public readonly ?string $rawText = null,
    ) {}

    public function responseHash(): string
    {
        return hash('sha256', json_encode($this->data, JSON_THROW_ON_ERROR));
    }

    public function asCacheHit(): self
    {
        return new self(
            $this->data, $this->modelKey, 0, 0, 0, 0,
            $this->finishReason, $this->degraded, true, $this->rawText,
        );
    }
}
