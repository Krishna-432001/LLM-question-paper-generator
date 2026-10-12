<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Book extends Model
{
    use BelongsToOrganization;
    use HasUuidV7;

    public const SHARES_PLATFORM_CONTENT = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_indexed' => 'boolean'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function mediumLanguage(): BelongsTo
    {
        return $this->belongsTo(Language::class, 'medium_language_id');
    }

    public function pages(): HasMany
    {
        return $this->hasMany(BookPage::class)->orderBy('page_number');
    }

    public function chapters(): HasMany
    {
        return $this->hasMany(BookChapter::class)->orderBy('sequence');
    }

    public function pagesNeedingReview(): HasMany
    {
        return $this->hasMany(BookPage::class)->where('needs_review', true);
    }
}
