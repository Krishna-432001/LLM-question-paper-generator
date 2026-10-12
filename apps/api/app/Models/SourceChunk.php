<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The unit of citation. Every generated question points at one of these, which
 * points at a page range in a book — the chain that makes a question checkable.
 */
class SourceChunk extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'importance' => 'float',
            'is_exercise' => 'boolean',
            'token_count' => 'integer',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(BookLesson::class, 'book_lesson_id');
    }

    /** Book-back exercises are never generation input — they are seed questions. */
    public function scopeGenerationSource(Builder $query): Builder
    {
        return $query->where('is_exercise', false);
    }

    public function scopeForLesson(Builder $query, string $lessonId): Builder
    {
        return $query->where('book_lesson_id', $lessonId);
    }

    /** Human-readable citation, e.g. "6th Science › Unit 3 › L3.2 › pp.42-43". */
    public function citation(): string
    {
        return $this->breadcrumb;
    }
}
