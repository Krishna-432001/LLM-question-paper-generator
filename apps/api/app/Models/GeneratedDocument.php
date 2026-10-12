<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneratedDocument extends Model
{
    use BelongsToOrganization;
    use HasUuidV7;

    public const SHARES_PLATFORM_CONTENT = true;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'render_payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->storage_path !== null;
    }
}
