<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestQuestion extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['marks' => 'float', 'substituted' => 'boolean', 'option_order' => 'array'];
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(Test::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** The translation the paper's primary language was assembled from. */
    public function translation(): BelongsTo
    {
        return $this->belongsTo(QuestionTranslation::class, 'question_translation_id');
    }

    /** The translation a bilingual paper's second language was assembled from. */
    public function secondaryTranslation(): BelongsTo
    {
        return $this->belongsTo(QuestionTranslation::class, 'secondary_question_translation_id');
    }

    /**
     * Set on a question from a part printed in one language whatever the
     * paper's (TNPSC Group IV Part A is Tamil): it prints in that language
     * alone, from its primary pin.
     */
    public function fixedLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'fixed_language_id');
    }
}
