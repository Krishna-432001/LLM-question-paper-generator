<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PromptTemplate extends Model
{
    use HasUuidV7;

    protected $guarded = ['id'];

    public function versions(): HasMany
    {
        return $this->hasMany(PromptVersion::class)->orderByDesc('version');
    }

    public function activeVersion(): HasOne
    {
        return $this->hasOne(PromptVersion::class)->where('status', 'active');
    }
}
