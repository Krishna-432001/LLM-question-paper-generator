<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookTopic extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['importance' => 'float'];
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(BookLesson::class, 'book_lesson_id');
    }
}
