<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The citation. Without one, a question cannot reach APPROVED — DB-enforced. */
class QuestionSource extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['relevance' => 'float', 'is_primary' => 'boolean'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function chunk(): BelongsTo
    {
        return $this->belongsTo(SourceChunk::class, 'source_chunk_id');
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(BookLesson::class, 'book_lesson_id');
    }

    public function citation(): string
    {
        return trim(sprintf(
            '%s%s',
            $this->breadcrumb ?? $this->chunk?->breadcrumb ?? 'Unknown source',
            $this->page_start ? " (pp.{$this->page_start}-{$this->page_end})" : '',
        ));
    }
}
