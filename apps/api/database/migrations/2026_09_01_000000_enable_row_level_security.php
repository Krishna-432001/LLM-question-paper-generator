<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Layer 3 of tenant isolation.
 *
 * Layer 1 is the ResolveTenant middleware, layer 2 is the Eloquent global
 * scope. Both live in application code and both can be bypassed by a bug, a
 * raw query, or a forgotten trait. This layer cannot: the database itself
 * refuses to return another tenant's rows.
 *
 * Two content classes:
 *   - SHARED  : organization_id IS NULL means platform-owned, readable by every
 *               tenant, writable only by platform admins. (exams, sources...)
 *   - PRIVATE : organization_id must always match the caller. (jobs, billing...)
 */
return new class extends Migration
{
    /** Tenant content readable across tenants when organization_id IS NULL. */
    private const SHARED = ['exams', 'sources', 'books'];

    /** Strictly tenant-private tables. */
    private const PRIVATE_TABLES = [
        'ai_jobs', 'subscriptions', 'payments', 'usage_records',
    ];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION current_user_id() RETURNS uuid AS $$
            BEGIN
                RETURN nullif(current_setting('app.user_id', true), '')::uuid;
            EXCEPTION WHEN others THEN
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql STABLE;

            -- Escape hatch for migrations, seeders and maintenance jobs, which
            -- legitimately operate outside any tenant. Request-serving code
            -- never sets this; in production the app connects as a role that
            -- is not the table owner, so this is defence in depth only.
            CREATE OR REPLACE FUNCTION rls_bypass() RETURNS boolean AS $$
            BEGIN
                RETURN coalesce(current_setting('app.bypass_rls', true), 'off') = 'on';
            END;
            $$ LANGUAGE plpgsql STABLE;
        SQL);

        foreach (self::SHARED as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;

                CREATE POLICY {$table}_tenant_read ON {$table} FOR SELECT
                USING (
                    rls_bypass()
                    OR is_platform_admin()
                    OR organization_id IS NULL
                    OR organization_id = current_org_id()
                );

                CREATE POLICY {$table}_tenant_write ON {$table}
                USING (
                    rls_bypass()
                    OR (is_platform_admin() AND organization_id IS NULL)
                    OR organization_id = current_org_id()
                )
                WITH CHECK (
                    rls_bypass()
                    OR (is_platform_admin() AND organization_id IS NULL)
                    OR organization_id = current_org_id()
                );
            SQL);
        }

        foreach (self::PRIVATE_TABLES as $table) {
            DB::unprepared(<<<SQL
                ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY;
                ALTER TABLE {$table} FORCE ROW LEVEL SECURITY;

                CREATE POLICY {$table}_tenant_all ON {$table}
                USING (
                    rls_bypass()
                    OR is_platform_admin()
                    OR organization_id = current_org_id()
                )
                WITH CHECK (
                    rls_bypass()
                    OR organization_id = current_org_id()
                );
            SQL);
        }

        // Organizations and memberships need membership-aware policies: a user
        // with two organizations must be able to list both in order to switch.
        DB::unprepared(<<<'SQL'
            ALTER TABLE organizations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE organizations FORCE ROW LEVEL SECURITY;

            CREATE POLICY organizations_read ON organizations FOR SELECT
            USING (
                rls_bypass()
                OR is_platform_admin()
                OR id = current_org_id()
                OR EXISTS (
                    SELECT 1 FROM organization_users ou
                    WHERE ou.organization_id = organizations.id
                      AND ou.user_id = current_user_id()
                )
            );

            CREATE POLICY organizations_write ON organizations
            USING (rls_bypass() OR is_platform_admin() OR id = current_org_id())
            WITH CHECK (rls_bypass() OR is_platform_admin() OR id = current_org_id());

            ALTER TABLE organization_users ENABLE ROW LEVEL SECURITY;
            ALTER TABLE organization_users FORCE ROW LEVEL SECURITY;

            CREATE POLICY organization_users_all ON organization_users
            USING (
                rls_bypass()
                OR is_platform_admin()
                OR organization_id = current_org_id()
                OR user_id = current_user_id()
            )
            WITH CHECK (
                rls_bypass()
                OR is_platform_admin()
                OR organization_id = current_org_id()
            );

            -- Audit rows are append-only. Even a compromised application role
            -- must not be able to rewrite history.
            ALTER TABLE audit_logs ENABLE ROW LEVEL SECURITY;
            ALTER TABLE audit_logs FORCE ROW LEVEL SECURITY;

            CREATE POLICY audit_logs_read ON audit_logs FOR SELECT
            USING (rls_bypass() OR is_platform_admin() OR organization_id = current_org_id());

            CREATE POLICY audit_logs_insert ON audit_logs FOR INSERT
            WITH CHECK (true);
        SQL);
    }

    public function down(): void
    {
        $all = array_merge(self::SHARED, self::PRIVATE_TABLES, [
            'organizations', 'organization_users', 'audit_logs',
        ]);

        foreach ($all as $table) {
            DB::unprepared("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");

            /** @var list<object{policyname: string}> $policies */
            $policies = DB::select(
                'SELECT policyname FROM pg_policies WHERE tablename = ?',
                [$table]
            );

            foreach ($policies as $policy) {
                DB::unprepared("DROP POLICY IF EXISTS {$policy->policyname} ON {$table}");
            }
        }

        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS rls_bypass();
            DROP FUNCTION IF EXISTS current_user_id();
        SQL);
    }
};
