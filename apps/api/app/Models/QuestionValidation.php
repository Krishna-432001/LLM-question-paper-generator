<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionValidation extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'question_validations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
            'passed' => 'boolean',
            'score' => 'float',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
