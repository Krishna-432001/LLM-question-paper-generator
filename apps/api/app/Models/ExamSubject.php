<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamSubject extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'is_optional' => 'boolean',
        ];
    }

    public function examPaper(): BelongsTo
    {
        return $this->belongsTo(ExamPaper::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ExamUnit::class)->orderBy('sequence');
    }
}
