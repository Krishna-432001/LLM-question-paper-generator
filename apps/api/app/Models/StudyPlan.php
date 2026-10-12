<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuidV7;
use App\Support\Database\PostgresArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudyPlan extends Model
{
    use BelongsToOrganization;
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'exam_date' => 'date',
            'hours_per_day' => 'float',
            'off_days' => PostgresArray::class,
            'dropped_topics' => 'array',
            'coverage_percent' => 'float',
        ];
    }

    public function days(): HasMany
    {
        return $this->hasMany(StudyPlanDay::class)->orderBy('day_number');
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function syllabusVersion(): BelongsTo
    {
        return $this->belongsTo(SyllabusVersion::class);
    }

    /** A plan the student already followed is history, not a projection. */
    public function futureDays(): HasMany
    {
        return $this->days()->where('plan_date', '>=', now()->toDateString())->where('is_locked', false);
    }
}
