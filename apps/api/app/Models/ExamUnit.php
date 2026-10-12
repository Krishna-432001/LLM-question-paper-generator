<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExamUnit extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['weight' => 'float'];
    }

    public function syllabusVersion(): BelongsTo
    {
        return $this->belongsTo(SyllabusVersion::class);
    }

    public function examSubject(): BelongsTo
    {
        return $this->belongsTo(ExamSubject::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(ExamTopic::class)->whereNull('parent_id')->orderBy('sequence');
    }
}
