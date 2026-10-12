<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudyPlanItem extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(StudyPlanDay::class, 'study_plan_day_id');
    }

    public function canonicalTopic(): BelongsTo
    {
        return $this->belongsTo(CanonicalTopic::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(BookLesson::class, 'book_lesson_id');
    }
}
