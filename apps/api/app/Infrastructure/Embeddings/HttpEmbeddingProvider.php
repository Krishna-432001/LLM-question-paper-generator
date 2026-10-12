<?php

declare(strict_types=1);

namespace App\Infrastructure\Embeddings;

use App\Domain\Ingestion\Contracts\EmbeddingProvider;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Self-hosted multilingual-e5-large behind a small HTTP service.
 *
 * Embeddings are a recurring per-chunk cost that recurs again on every
 * re-index, so we own the model rather than renting it. e5 also puts Tamil and
 * English in one shared space, which is what lets a Tamil query retrieve an
 * English source chunk.
 */
final class HttpEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly string $endpoint,
        private readonly string $model = 'multilingual-e5-large',
        private readonly int $dimensions = 1024,
        private readonly int $timeoutSeconds = 120,
    ) {}

    public function modelKey(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     */
    public function embed(array $texts): array
    {
        if ($texts === []) {
            return [];
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->retry(3, 500, throw: false)
            ->post($this->endpoint, [
                'model' => $this->model,
                // e5 is asymmetric and expects this prefix on indexed passages;
                // omitting it measurably degrades retrieval quality.
                'inputs' => array_map(static fn (string $t): string => 'passage: '.$t, $texts),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Embedding service returned {$response->status()}: ".$response->body()
            );
        }

        /** @var list<list<float>> $vectors */
        $vectors = $response->json('embeddings') ?? [];

        if (count($vectors) !== count($texts)) {
            throw new RuntimeException(
                'Embedding service returned '.count($vectors).' vectors for '.count($texts).' inputs.'
            );
        }

        return $vectors;
    }
}
