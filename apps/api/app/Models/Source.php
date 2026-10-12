<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Source extends Model
{
    use BelongsToOrganization;
    use HasUuidV7;

    public const SHARES_PLATFORM_CONTENT = true;

    /** Ordered ingestion stages. pipeline_state tracks each one. */
    public const STAGES = [
        'scan', 'extract', 'normalize', 'structure', 'chunk', 'embed', 'map',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'pipeline_state' => 'array',
            'ocr_confidence' => 'float',
            'file_size_bytes' => 'integer',
        ];
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function book(): HasOne
    {
        return $this->hasOne(Book::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(AiJob::class, 'subject_id')
            ->where('subject_type', self::class);
    }

    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', 'ready');
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function stageStatus(string $stage): ?string
    {
        return $this->pipeline_state[$stage]['status'] ?? null;
    }

    public function markStage(string $stage, string $status, ?string $jobId = null): void
    {
        $state = $this->pipeline_state ?? [];
        $state[$stage] = array_filter([
            'status' => $status,
            'at' => now()->toIso8601String(),
            'job_id' => $jobId,
        ]);

        $this->pipeline_state = $state;
        $this->save();
    }
}
