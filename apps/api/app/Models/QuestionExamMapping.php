<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionExamMapping extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $table = 'question_exam_mappings';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'suitability' => 'float',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
