<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Domain\Tenancy\TenantContext;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Layer 2 of tenant isolation: a global scope on every read and an automatic
 * organization_id on every write.
 *
 * Models set SHARES_PLATFORM_CONTENT = true when organization_id IS NULL means
 * "platform-owned, readable by all tenants" (exams, books, approved questions).
 * Private models (jobs, attempts, billing) must leave it false — a student's
 * attempt is never platform content.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query): void {
            if (! app()->bound(TenantContext::class)) {
                return;   // console, seeders, maintenance jobs
            }

            $context = app(TenantContext::class);

            if ($context->isPlatformAdmin) {
                return;   // platform admins curate content across all tenants
            }

            $table = $query->getModel()->getTable();

            $query->where(function (Builder $query) use ($table, $context): void {
                $query->where("{$table}.organization_id", $context->organizationId);

                if (static::sharesPlatformContent()) {
                    $query->orWhereNull("{$table}.organization_id");
                }
            });
        });

        static::creating(function ($model): void {
            if ($model->organization_id === null
                && ! $model->platformOwned
                && app()->bound(TenantContext::class)) {
                $model->organization_id = app(TenantContext::class)->organizationId;
            }
        });
    }

    /**
     * A null organization_id alone is not enough to create platform content
     * inside a request: the creating hook reads null as "not set yet" and
     * fills in the caller's organization. This flag is a plain property, not
     * an attribute, so it is never written to the table.
     */
    public bool $platformOwned = false;

    /** Mark an unsaved model as platform-owned (organization_id IS NULL). */
    public function asPlatformOwned(): static
    {
        $this->platformOwned = true;
        $this->organization_id = null;

        return $this;
    }

    public static function sharesPlatformContent(): bool
    {
        return defined(static::class.'::SHARES_PLATFORM_CONTENT')
            && constant(static::class.'::SHARES_PLATFORM_CONTENT') === true;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Rows owned by the platform rather than by any tenant. */
    public function scopePlatformOwned(Builder $query): Builder
    {
        return $query->whereNull('organization_id');
    }

    public function isPlatformOwned(): bool
    {
        return $this->organization_id === null;
    }
}
