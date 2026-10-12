<?php

declare(strict_types=1);

namespace App\Domain\Rag;

use App\Domain\Ingestion\Contracts\EmbeddingProvider;
use App\Models\SourceChunk;
use App\Support\Database\VectorSupport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Hybrid retrieval: vector kNN fused with BM25-style full-text search.
 *
 * Neither alone is sufficient. Vector search misses exact terminology
 * ("Article 21"); keyword search misses paraphrase ("pumps blood" vs
 * "circulates blood"). Reciprocal Rank Fusion combines them without needing
 * the two score scales to be comparable.
 */
final class Retriever
{
    public function __construct(private readonly EmbeddingProvider $embeddings) {}

    /**
     * @param  array<string, mixed>  $scope  book_id / book_lesson_id / source_id
     * @return Collection<int, SourceChunk>
     */
    public function retrieve(string $query, array $scope = [], ?int $limit = null): Collection
    {
        $limit ??= (int) config('embeddings.retrieval.rerank_top_n', 12);

        $vectorHits = $this->vectorSearch($query, $scope);
        $keywordHits = $this->keywordSearch($query, $scope);

        $fused = $this->reciprocalRankFusion([$vectorHits, $keywordHits]);

        if ($fused === []) {
            return collect();
        }

        $ids = array_slice(array_keys($fused), 0, $limit);

        $chunks = SourceChunk::query()
            ->whereIn('id', $ids)
            ->generationSource()   // book-back exercises are never RAG input
            ->get()
            ->keyBy('id');

        // Preserve fusion order, then re-sort by page so the packed context
        // reads in the order the book presents it.
        return collect($ids)
            ->map(fn (string $id) => $chunks->get($id))
            ->filter()
            ->sortBy(fn (SourceChunk $c): int => $c->page_start ?? 0)
            ->values();
    }

    /**
     * A topic with too few supporting chunks is NOT COVERED by the uploaded
     * sources. Refusing here is what stops the system inventing content — the
     * single most common failure of naive implementations.
     */
    public function hasSufficientCoverage(Collection $chunks): bool
    {
        return $chunks->count() >= (int) config('embeddings.retrieval.require_chunks', 3);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<string>  chunk ids, best first
     */
    private function vectorSearch(string $query, array $scope): array
    {
        // e5 is asymmetric: queries and passages take different prefixes.
        $vector = $this->embeddings->embed(['query: '.$query])[0] ?? null;

        if ($vector === null) {
            return [];
        }

        $binding = VectorSupport::toBinding($vector);
        $distance = VectorSupport::distanceExpression('e.embedding', '?');
        [$where, $params] = $this->scopeSql($scope, 'c');
        $k = (int) config('embeddings.retrieval.vector_k', 40);

        $rows = DB::select(
            "SELECT c.id, {$distance} AS distance
             FROM source_chunks c
             JOIN source_embeddings e ON e.source_chunk_id = c.id
             WHERE c.is_exercise = false {$where}
             ORDER BY distance ASC
             LIMIT {$k}",
            [$binding, ...$params],
        );

        return array_map(static fn (object $r): string => $r->id, $rows);
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return list<string>
     */
    private function keywordSearch(string $query, array $scope): array
    {
        [$where, $params] = $this->scopeSql($scope, 'c');
        $k = (int) config('embeddings.retrieval.keyword_k', 40);

        // websearch_to_tsquery tolerates natural phrasing and never throws on
        // punctuation, unlike to_tsquery.
        $rows = DB::select(
            "SELECT c.id, ts_rank(c.fts, websearch_to_tsquery('simple', ?)) AS rank
             FROM source_chunks c
             WHERE c.is_exercise = false
               AND c.fts @@ websearch_to_tsquery('simple', ?) {$where}
             ORDER BY rank DESC
             LIMIT {$k}",
            [$query, $query, ...$params],
        );

        return array_map(static fn (object $r): string => $r->id, $rows);
    }

    /**
     * RRF: score = sum over rankings of 1 / (k + rank). Rank-based, so the two
     * searches' incomparable score scales never have to be normalized.
     *
     * @param  list<list<string>>  $rankings
     * @return array<string, float>  id => score, best first
     */
    private function reciprocalRankFusion(array $rankings): array
    {
        $k = (int) config('embeddings.retrieval.rrf_k', 60);
        $scores = [];

        foreach ($rankings as $ranking) {
            foreach ($ranking as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0.0) + 1 / ($k + $rank + 1);
            }
        }

        arsort($scores);

        return $scores;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @return array{0: string, 1: list<mixed>}
     */
    private function scopeSql(array $scope, string $alias): array
    {
        $clauses = [];
        $params = [];

        foreach (['source_id', 'book_id', 'book_lesson_id'] as $column) {
            if (! isset($scope[$column])) {
                continue;
            }

            $values = (array) $scope[$column];
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $clauses[] = "AND {$alias}.{$column} IN ({$placeholders})";
            $params = [...$params, ...$values];
        }

        return [implode(' ', $clauses), $params];
    }
}
