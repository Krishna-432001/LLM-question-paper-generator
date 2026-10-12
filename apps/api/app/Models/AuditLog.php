<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only, enforced by a database trigger rather than by convention: any
 * UPDATE or DELETE raises, whoever issues it, so application code cannot
 * rewrite what it did.
 *
 * The honest limit: anyone able to drop the trigger — a superuser, or the
 * owning role — can still rewrite history. Resisting that needs a separately
 * owned schema or off-box shipping, and this class does not claim it.
 */
class AuditLog extends Model
{
    use HasUuidV7;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
