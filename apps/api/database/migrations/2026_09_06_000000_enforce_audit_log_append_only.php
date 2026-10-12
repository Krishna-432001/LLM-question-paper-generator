<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Makes the append-only audit log actually append-only.
 *
 * The model has claimed since it was written that "the database revokes UPDATE
 * and DELETE for the application role, so history cannot be rewritten even by
 * compromised app code". No such revocation existed. A security property that
 * lives only in a docblock is worse than an acknowledged gap, because everyone
 * downstream reasons as though it holds.
 *
 * A REVOKE alone would not do it either: the application role owns these
 * tables, and an owner can grant privileges back to itself. A trigger cannot
 * be sidestepped that way — any UPDATE or DELETE raises, whoever issues it.
 *
 * What this does NOT claim: a superuser, or anyone who can drop the trigger,
 * can still rewrite history. Immutability against that requires a separately
 * owned schema or shipping entries off-box, and pretending otherwise here
 * would repeat the mistake this migration exists to fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'audit_logs is append-only: % is not permitted', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql
        SQL);

        // Partitioned tables do not fire row triggers on the parent, so the
        // guard is attached to each partition as well as to the parent for
        // statement-level operations.
        $tables = DB::select(<<<'SQL'
            SELECT c.relname AS name
              FROM pg_class c
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public'
               AND (c.relname = 'audit_logs' OR c.relname LIKE 'audit_logs_p%')
               AND c.relkind IN ('r', 'p')
        SQL);

        foreach ($tables as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table->name}_append_only ON {$table->name}");
            DB::statement(<<<SQL
                CREATE TRIGGER {$table->name}_append_only
                    BEFORE UPDATE OR DELETE ON {$table->name}
                    FOR EACH STATEMENT EXECUTE FUNCTION audit_logs_append_only()
            SQL);
        }

        // Belt as well as braces: the revocation the model always claimed.
        // It does not bind the owner, but it does bind every other role.
        DB::statement('REVOKE UPDATE, DELETE ON audit_logs FROM PUBLIC');
    }

    public function down(): void
    {
        $tables = DB::select(<<<'SQL'
            SELECT c.relname AS name
              FROM pg_class c
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public'
               AND (c.relname = 'audit_logs' OR c.relname LIKE 'audit_logs_p%')
               AND c.relkind IN ('r', 'p')
        SQL);

        foreach ($tables as $table) {
            DB::statement("DROP TRIGGER IF EXISTS {$table->name}_append_only ON {$table->name}");
        }

        DB::statement('DROP FUNCTION IF EXISTS audit_logs_append_only()');
    }
};
