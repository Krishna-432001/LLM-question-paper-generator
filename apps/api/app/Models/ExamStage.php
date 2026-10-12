<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamStage extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_qualifying' => 'boolean'];
    }

    public function examVersion(): BelongsTo
    {
        return $this->belongsTo(ExamVersion::class);
    }

    public function papers(): HasMany
    {
        return $this->hasMany(ExamPaper::class)->orderBy('sequence');
    }
}
