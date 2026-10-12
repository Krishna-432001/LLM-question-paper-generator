<?php

declare(strict_types=1);

namespace App\Infrastructure\Embeddings;

use App\Domain\Ingestion\Contracts\EmbeddingProvider;

/**
 * Deterministic hash-based embeddings.
 *
 * Not semantically meaningful, but stable and similarity-preserving enough for
 * tests: identical text yields identical vectors, and shared tokens raise
 * cosine similarity. That is sufficient to exercise retrieval, deduplication
 * and the storage path without a model server.
 */
final class FakeEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(private readonly int $dimensions = 1024) {}

    public function modelKey(): string
    {
        return 'fake-hash-embedding';
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
        return array_map($this->vectorise(...), $texts);
    }

    /** @return list<float> */
    private function vectorise(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);

        // Bag-of-tokens projected into the vector space: shared vocabulary
        // produces overlapping dimensions, so cosine similarity behaves.
        foreach (preg_split('/\W+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $slot = (int) (hexdec(substr(md5($token), 0, 8)) % $this->dimensions);
            $vector[$slot] += 1.0;
        }

        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        if ($norm <= 0.0) {
            $vector[0] = 1.0;

            return $vector;
        }

        return array_map(static fn (float $v): float => $v / $norm, $vector);
    }
}
