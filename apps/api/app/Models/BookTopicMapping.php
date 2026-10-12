<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Links book content to the syllabus. Drives coverage reports and retrieval scoping. */
class BookTopicMapping extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['confidence' => 'float'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(BookLesson::class, 'book_lesson_id');
    }

    public function canonicalTopic(): BelongsTo
    {
        return $this->belongsTo(CanonicalTopic::class);
    }

    public function examTopic(): BelongsTo
    {
        return $this->belongsTo(ExamTopic::class);
    }
}
