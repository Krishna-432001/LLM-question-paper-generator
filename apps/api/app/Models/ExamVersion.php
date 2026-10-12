<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamVersion extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'notification_date' => 'date',
            'exam_date' => 'date',
        ];
    }

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ExamStage::class)->orderBy('sequence');
    }

    public function languages(): BelongsToMany
    {
        return $this->belongsToMany(Language::class, 'exam_languages')
            ->withPivot('is_default');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
