<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cache of the PYQ analysis, rebuilt wholesale and never edited by hand.
 * Not tenant-scoped: it describes an exam, not an organisation.
 */
class TopicFrequency extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'total_asked' => 'integer',
            'years_asked' => 'integer',
            'years_covered' => 'integer',
            'first_asked_year' => 'integer',
            'last_asked_year' => 'integer',
            'frequency_score' => 'float',
            'difficulty_mix' => 'array',
            'type_mix' => 'array',
            'computed_at' => 'datetime',
        ];
    }

    public function canonicalTopic(): BelongsTo
    {
        return $this->belongsTo(CanonicalTopic::class);
    }
}
