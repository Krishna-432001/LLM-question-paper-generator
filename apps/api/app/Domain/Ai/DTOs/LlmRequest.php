<?php

declare(strict_types=1);

namespace App\Domain\Ai\DTOs;

final class LlmRequest
{
    /**
     * @param  array<string, mixed>  $outputSchema  JSON Schema the response must satisfy
     * @param  array<string, mixed>  $params        temperature, max_tokens, ...
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $modelKey,
        public readonly string $systemPrompt,
        public readonly string $userPrompt,
        public readonly array $outputSchema,
        public readonly array $params = [],
        public readonly ?string $cachePrefix = null,
    ) {}

    /** Stable fingerprint used for the exact-response cache and dedup of retries. */
    public function hash(): string
    {
        return hash('sha256', json_encode([
            $this->modelKey,
            $this->systemPrompt,
            $this->userPrompt,
            $this->outputSchema,
            $this->params,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param  array<string, mixed>  $params */
    public function withParams(array $params): self
    {
        return new self(
            $this->operation,
            $this->modelKey,
            $this->systemPrompt,
            $this->userPrompt,
            $this->outputSchema,
            [...$this->params, ...$params],
            $this->cachePrefix,
        );
    }

    public function withModel(string $modelKey): self
    {
        return new self(
            $this->operation,
            $modelKey,
            $this->systemPrompt,
            $this->userPrompt,
            $this->outputSchema,
            $this->params,
            $this->cachePrefix,
        );
    }

    /** Appends a validator error so the model can repair its own output. */
    public function withRepairHint(string $error): self
    {
        return new self(
            $this->operation,
            $this->modelKey,
            $this->systemPrompt,
            $this->userPrompt."\n\n<REPAIR>Your previous response failed schema validation: "
                .$error."\nReturn corrected JSON only.</REPAIR>",
            $this->outputSchema,
            $this->params,
            $this->cachePrefix,
        );
    }
}
