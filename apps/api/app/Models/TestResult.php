<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use App\Support\Database\PostgresArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestResult extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'topic_breakdown' => 'array',
            'difficulty_breakdown' => 'array',
            'weak_topics' => PostgresArray::class,
            'strong_topics' => PostgresArray::class,
            'raw_score' => 'float',
            'negative_score' => 'float',
            'final_score' => 'float',
            'accuracy' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(TestAttempt::class, 'test_attempt_id');
    }
}
