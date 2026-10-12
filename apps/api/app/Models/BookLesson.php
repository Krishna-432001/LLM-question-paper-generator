<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BookLesson extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    public function chapter(): BelongsTo
    {
        return $this->belongsTo(BookChapter::class, 'book_chapter_id');
    }

    public function topics(): HasMany
    {
        return $this->hasMany(BookTopic::class);
    }

    public function topicMappings(): HasMany
    {
        return $this->hasMany(BookTopicMapping::class);
    }

    public function pageRange(): string
    {
        return "pp.{$this->page_start}-{$this->page_end}";
    }
}
