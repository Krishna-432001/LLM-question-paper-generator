<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionRejection extends Model
{
    use HasUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'question_rejections';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'detail' => 'array',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
