<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamPaper extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'total_marks' => 'float',
            'negative_marking' => 'float',
        ];
    }

    public function examStage(): BelongsTo
    {
        return $this->belongsTo(ExamStage::class);
    }

    public function subjects(): HasMany
    {
        return $this->hasMany(ExamSubject::class)->orderBy('sequence');
    }

    public function blueprints(): HasMany
    {
        return $this->hasMany(ExamBlueprint::class);
    }
}
