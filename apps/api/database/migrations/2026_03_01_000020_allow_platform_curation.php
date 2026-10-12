<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Content reviewers must be able to curate platform-owned questions.
 *
 * The original write policy allowed only `organization_id = current_org_id()`
 * or a full platform admin. A content reviewer is neither, so their approvals
 * were filtered out by RLS — and because Postgres reports an UPDATE that
 * matches no rows as a success, the API returned 200 while nothing changed.
 *
 * The fix is a narrow capability rather than handing reviewers admin: the
 * policy now recognises "may curate platform content", set per request from
 * the questions.approve.platform permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION can_curate_platform() RETURNS boolean AS $$
            BEGIN
                RETURN coalesce(current_setting('app.can_curate_platform', true), 'off') = 'on';
            END;
            $$ LANGUAGE plpgsql STABLE;

            DROP POLICY IF EXISTS questions_tenant_write ON questions;
            CREATE POLICY questions_tenant_write ON questions
            USING (
                rls_bypass()
                OR ((is_platform_admin() OR can_curate_platform()) AND organization_id IS NULL)
                OR organization_id = current_org_id()
            )
            WITH CHECK (
                rls_bypass()
                OR ((is_platform_admin() OR can_curate_platform()) AND organization_id IS NULL)
                OR organization_id = current_org_id()
            );

            DROP POLICY IF EXISTS reviews_tenant ON reviews;
            CREATE POLICY reviews_tenant ON reviews
            USING (
                rls_bypass() OR is_platform_admin() OR can_curate_platform()
                OR organization_id IS NULL
                OR organization_id = current_org_id()
            )
            WITH CHECK (
                rls_bypass() OR is_platform_admin() OR can_curate_platform()
                OR organization_id IS NULL
                OR organization_id = current_org_id()
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS can_curate_platform()');
    }
};
