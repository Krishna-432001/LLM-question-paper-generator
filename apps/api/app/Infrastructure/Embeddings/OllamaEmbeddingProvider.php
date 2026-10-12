<?php

declare(strict_types=1);

namespace App\Infrastructure\Embeddings;

use App\Domain\Ingestion\Contracts\EmbeddingProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as Http;
use RuntimeException;

/**
 * Embeddings from a local Ollama model.
 *
 * bge-m3 is the recommended model: multilingual (Tamil included), 1024
 * dimensions — the width the vector column already has — and no query or
 * passage prefixes to get wrong.
 */
final class OllamaEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly Http $http,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly int $timeoutSeconds = 300,
    ) {}

    public function modelKey(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        try {
            $response = $this->http
                ->timeout($this->timeoutSeconds)
                ->post(rtrim($this->baseUrl, '/').'/api/embed', [
                    'model' => $this->model,
                    'input' => array_values($texts),
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Ollama is not reachable at {$this->baseUrl} for embeddings. Start it with `ollama serve`.", previous: $e);
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "Ollama embeddings failed ({$response->status()}): ".mb_substr((string) ($response->json('error') ?? $response->body()), 0, 300)
                ." — if the model is missing, run: ollama pull {$this->model}"
            );
        }

        $vectors = (array) $response->json('embeddings', []);

        if (count($vectors) !== count($texts)) {
            throw new RuntimeException('Ollama returned '.count($vectors).' embeddings for '.count($texts).' texts.');
        }

        foreach ($vectors as $vector) {
            // A model of another width would silently corrupt the index.
            if (count((array) $vector) !== $this->dimensions) {
                throw new RuntimeException(
                    "Embedding model {$this->model} returns ".count((array) $vector)." dimensions, but the index stores {$this->dimensions}. "
                    .'Use a 1024-dimension model such as bge-m3.'
                );
            }
        }

        return array_map(static fn ($v): array => array_map('floatval', (array) $v), array_values($vectors));
    }
}
