<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BlueprintSection extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'topic_weights' => 'array',
            'difficulty_mix' => 'array',
            'marks_per_question' => 'float',
            'qualifying_percent' => 'float',
        ];
    }

    /** The one language this part is printed in, whatever the paper's; null when it follows the paper. */
    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(ExamBlueprint::class, 'blueprint_id');
    }

    public function examSubject(): BelongsTo
    {
        return $this->belongsTo(ExamSubject::class);
    }
}
