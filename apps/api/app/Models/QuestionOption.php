<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Canonical option structure and — critically — the answer.
 *
 * `is_correct` lives here and nowhere else. A translation may change the words
 * of an option but can never change which one is right, so a Tamil paper's key
 * can never diverge from the English one.
 */
class QuestionOption extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_correct' => 'boolean'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
