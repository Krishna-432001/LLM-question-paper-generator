<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * pgvector is optional in development and required in production.
 *
 * Where it is present we store `vector(N)` and search with an HNSW index.
 * Where it is not, embeddings still work — they are stored as `real[]` and
 * searched by brute-force cosine in SQL. That is correct but O(n), which is
 * fine for a few thousand development chunks and unusable at production scale.
 *
 * Keeping both paths behind this class means no calling code has to know, and
 * installing pgvector later is a migration rather than a rewrite.
 */
final class VectorSupport
{
    private static ?bool $available = null;

    public static function isAvailable(): bool
    {
        return self::$available ??= (bool) DB::selectOne(
            "SELECT 1 AS ok FROM pg_extension WHERE extname = 'vector'"
        );
    }

    /** Column type for an embedding of the configured dimensionality. */
    public static function columnType(): string
    {
        return self::isAvailable()
            ? 'vector('.self::dimensions().')'
            : 'real[]';
    }

    public static function dimensions(): int
    {
        return (int) config('embeddings.dimensions', 1024);
    }

    /**
     * SQL fragment returning cosine DISTANCE (0 = identical) between a stored
     * embedding column and a bound parameter.
     */
    public static function distanceExpression(string $column, string $placeholder = '?'): string
    {
        if (self::isAvailable()) {
            return "{$column} <=> {$placeholder}::vector";
        }

        // Brute-force cosine distance over real[]: 1 - (a·b)/(|a||b|).
        //
        // The placeholder must appear EXACTLY ONCE, matching the pgvector
        // branch: callers bind one parameter, and a branch that consumed two
        // would make the parameter count depend on whether an extension is
        // installed. The query vector is unit-normalised by toBinding(), so
        // its norm is 1 and drops out of the denominator.
        return <<<SQL
            (1 - (
                (SELECT sum(a * b) FROM unnest({$column}, {$placeholder}::real[]) AS t(a, b))
                / NULLIF(sqrt((SELECT sum(a * a) FROM unnest({$column}) AS a)), 0)
            ))
        SQL;
    }

    /**
     * Bind format differs: pgvector wants '[1,2,3]', a real[] wants '{1,2,3}'.
     *
     * @param  list<float>  $embedding
     */
    public static function toBinding(array $embedding): string
    {
        // Unit-normalise so cosine distance reduces to the dot product. This
        // is what lets the real[] fallback bind a single parameter, and it is
        // a no-op for providers that already emit normalised vectors.
        $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $embedding)));

        if ($norm > 0.0) {
            $embedding = array_map(static fn (float $v): float => $v / $norm, $embedding);
        }

        $values = implode(',', array_map(
            static fn (float $v): string => rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') ?: '0',
            $embedding,
        ));

        return self::isAvailable() ? "[{$values}]" : "{{$values}}";
    }

    /** @return list<float> */
    public static function fromDatabase(string $raw): array
    {
        return array_map(
            static fn (string $v): float => (float) $v,
            explode(',', trim($raw, '[]{}')),
        );
    }

    public static function flushCache(): void
    {
        self::$available = null;
    }
}
