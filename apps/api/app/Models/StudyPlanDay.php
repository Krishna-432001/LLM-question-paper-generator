<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudyPlanDay extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'plan_date' => 'date',
            'is_locked' => 'boolean',
            'completed_at' => 'datetime',
            'completion_pct' => 'float',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(StudyPlan::class, 'study_plan_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StudyPlanItem::class)->orderBy('sequence');
    }
}
