<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    use BelongsToOrganization;
    use HasUuidV7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'cost_inr' => 'decimal:4',
            'occurred_at' => 'datetime',
        ];
    }
}
