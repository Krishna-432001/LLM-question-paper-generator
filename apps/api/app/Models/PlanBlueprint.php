<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use App\Support\Database\PostgresArray;
use Illuminate\Database\Eloquent\Model;

/**
 * The five plan modes are rows, not five code paths. Adding a "45-day crash
 * plan" is an insert.
 */
class PlanBlueprint extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'structure' => 'array',
            'test_config' => 'array',
            'documents' => PostgresArray::class,
            'is_default' => 'boolean',
        ];
    }

    public static function forMode(string $mode): self
    {
        return static::query()->where('mode', $mode)->where('is_default', true)->firstOrFail();
    }
}
