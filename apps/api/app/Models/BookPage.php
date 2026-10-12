<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookPage extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'layout' => 'array',
            'ocr_confidence' => 'float',
            'needs_review' => 'boolean',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Text a downstream stage may trust: reviewed, or high-confidence OCR. */
    public function usableText(): ?string
    {
        return $this->needs_review ? null : ($this->normalized_text ?? $this->raw_text);
    }
}
