<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;

/**
 * UUIDv7 primary keys: time-sortable (so they index like a bigint), yet
 * non-enumerable (so an ID in a URL leaks nothing and cannot be walked).
 */
trait HasUuidV7
{
    use HasUuids;

    public function newUniqueId(): string
    {
        return method_exists(Str::class, 'uuid7')
            ? (string) Str::uuid7()
            : (string) Str::orderedUuid();
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
