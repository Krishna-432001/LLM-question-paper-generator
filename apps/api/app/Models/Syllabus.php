<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Syllabus extends Model
{
    use HasUuidV7;

    protected $table = 'syllabi';

    protected $guarded = ['id'];

    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SyllabusVersion::class);
    }

    public function activeVersion(): ?SyllabusVersion
    {
        return $this->versions()->where('status', 'active')->first();
    }
}
