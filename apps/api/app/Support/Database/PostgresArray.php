<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Cast for native PostgreSQL array columns (uuid[], text[], int[]).
 *
 * Laravel's built-in 'array' cast serialises to JSON, which Postgres rejects
 * for a real array column: `[]` is not a valid array literal, `{}` is. Using
 * a native array type rather than jsonb is deliberate here — `weak_topics`
 * and `off_days` are queried with array operators, which jsonb makes clumsy.
 *
 * @implements CastsAttributes<list<string>, list<string>>
 */
final class PostgresArray implements CastsAttributes
{
    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '{}') {
            return [];
        }

        if (is_array($value)) {
            return array_values($value);
        }

        $inner = trim((string) $value, '{}');

        if ($inner === '') {
            return [];
        }

        return array_map(
            static fn (string $v): string => trim($v, '"'),
            explode(',', $inner),
        );
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $values = array_values(array_filter((array) $value, static fn ($v): bool => $v !== null));

        if ($values === []) {
            return '{}';
        }

        return '{'.implode(',', array_map(
            static fn ($v): string => '"'.str_replace('"', '\"', (string) $v).'"',
            $values,
        )).'}';
    }
}
