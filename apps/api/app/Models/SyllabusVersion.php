<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable once active. Study plans, tests and question mappings all point at
 * a syllabus_version_id rather than a bare exam, so a student preparing under
 * the 2025 notification keeps their plan when the 2026 syllabus lands.
 */
class SyllabusVersion extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_to' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function syllabus(): BelongsTo
    {
        return $this->belongsTo(Syllabus::class);
    }

    public function examVersion(): BelongsTo
    {
        return $this->belongsTo(ExamVersion::class);
    }

    public function units(): HasMany
    {
        return $this->hasMany(ExamUnit::class)->orderBy('sequence');
    }

    public function topics(): HasMany
    {
        return $this->hasMany(ExamTopic::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}
