<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The resolved tenant for the current request, job or command.
 *
 * Bound into the container by ResolveTenant (HTTP) or TenantAware (queue).
 * Nothing in the application may read the organization from a request body or
 * header: it is always derived from an authenticated membership.
 */
final class TenantContext
{
    public function __construct(
        public readonly string $organizationId,
        public readonly ?string $userId = null,
        public readonly bool $isPlatformAdmin = false,
        public readonly ?Organization $organization = null,
        public readonly ?OrganizationUser $membership = null,
        /**
         * May edit platform-owned content without being a platform admin.
         * Narrower than admin: it grants curation, not configuration.
         */
        public readonly bool $canCuratePlatform = false,
    ) {}

    public static function forUser(User $user, Organization $organization): self
    {
        return new self(
            organizationId: $organization->getKey(),
            userId: $user->getKey(),
            // Cast: a freshly created model has not read the column's
            // DEFAULT false back from the database, so the attribute is null.
            isPlatformAdmin: $user->isPlatformAdmin(),
            organization: $organization,
            membership: $user->membershipFor($organization->getKey()),
            canCuratePlatform: $user->can('questions.approve.platform'),
        );
    }

    /**
     * Push the tenant into the PostgreSQL session so Row-Level Security can
     * see it. SET LOCAL is transaction-scoped, so this is re-applied per
     * transaction; outside a transaction we use SET (connection-scoped) and
     * reset it when the context is torn down.
     */
    public function applyToDatabase(): void
    {
        self::setVar('app.org_id', $this->organizationId);
        self::setVar('app.user_id', $this->userId ?? '');
        self::setVar('app.is_platform_admin', $this->isPlatformAdmin ? 'on' : 'off');
        self::setVar('app.can_curate_platform', $this->canCuratePlatform ? 'on' : 'off');
        self::setVar('app.bypass_rls', 'off');
    }

    /**
     * PostgreSQL's SET command is not a prepared statement and rejects bind
     * parameters, so interpolating a value into it would be the only option —
     * and interpolating a user-controlled id into SQL is exactly what we must
     * not do. set_config() is the function form: it takes real parameters.
     *
     * The third argument scopes the value to the transaction when we are in
     * one (so it unwinds with a rollback, including RefreshDatabase's), and to
     * the connection otherwise.
     */
    private static function setVar(string $name, string $value): void
    {
        DB::statement('SELECT set_config(?, ?, ?)', [
            $name,
            $value,
            DB::transactionLevel() > 0,
        ]);
    }

    /**
     * Chicken-and-egg fix: the RLS policy on `organizations` grants access via
     * a membership lookup keyed on current_user_id(). Resolving which
     * organization a user belongs to therefore requires the user id to already
     * be in the session — otherwise the policy hides the very row the
     * middleware is trying to read, and every request 403s.
     *
     * So the identity is published first, and the tenant second.
     */
    public static function seedUser(string $userId): void
    {
        self::setVar('app.user_id', $userId);
    }

    public static function clearDatabase(): void
    {
        self::setVar('app.org_id', '');
        self::setVar('app.user_id', '');
        self::setVar('app.is_platform_admin', 'off');
        self::setVar('app.can_curate_platform', 'off');
    }

    /**
     * Run a callback with RLS bypassed. Only for migrations, seeders and
     * maintenance jobs that legitimately operate across all tenants — never
     * on a request path.
     *
     * @template T
     *
     * @param  callable():T  $callback
     * @return T
     */
    public static function withoutTenancy(callable $callback): mixed
    {
        self::setVar('app.bypass_rls', 'on');

        try {
            return $callback();
        } finally {
            // If the callback aborted the transaction, this cleanup query
            // cannot run — and throwing here would replace the real exception
            // with a meaningless "transaction is aborted". Cleanup must never
            // mask the cause. The setting is transaction-scoped anyway, so a
            // rollback discards it.
            try {
                self::setVar('app.bypass_rls', 'off');
            } catch (\Throwable) {
                // Intentionally ignored.
            }
        }
    }
}
