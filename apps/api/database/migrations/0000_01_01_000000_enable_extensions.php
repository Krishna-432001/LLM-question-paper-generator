<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Extensions and shared SQL helpers.
 *
 * pgvector is deliberately NOT required here: Phase 1 (identity, tenancy,
 * catalogue, job ledger) does not use embeddings. The Phase 2 migration that
 * creates source_embeddings requires it and fails loudly if it is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS pgcrypto;
            CREATE EXTENSION IF NOT EXISTS pg_trgm;
            CREATE EXTENSION IF NOT EXISTS btree_gin;
            CREATE EXTENSION IF NOT EXISTS ltree;
            CREATE EXTENSION IF NOT EXISTS citext;

            -- UUIDv7: time-sortable primary keys. PostgreSQL 18 ships uuidv7()
            -- natively; this wrapper keeps us portable down to PG 14 (RDS).
            CREATE OR REPLACE FUNCTION uuid_generate_v7() RETURNS uuid AS $$
            DECLARE
                unix_ts_ms bytea;
                uuid_bytes bytea;
            BEGIN
                unix_ts_ms := substring(
                    int8send((extract(epoch FROM clock_timestamp()) * 1000)::bigint)
                    FROM 3
                );
                uuid_bytes := unix_ts_ms || gen_random_bytes(10);
                -- version 7 (high nibble of byte 6)
                uuid_bytes := set_byte(uuid_bytes, 6, (get_byte(uuid_bytes, 6) & 15) | 112);
                -- RFC 4122 variant (top two bits of byte 8)
                uuid_bytes := set_byte(uuid_bytes, 8, (get_byte(uuid_bytes, 8) & 63) | 128);
                RETURN encode(uuid_bytes, 'hex')::uuid;
            END;
            $$ LANGUAGE plpgsql VOLATILE;

            -- Keeps updated_at honest even for raw SQL writes from workers.
            CREATE OR REPLACE FUNCTION touch_updated_at() RETURNS trigger AS $$
            BEGIN
                NEW.updated_at := now();
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            -- Reads the tenant set by ResolveTenant middleware / TenantAware job
            -- middleware. Returns NULL outside a tenant context (console, tests).
            CREATE OR REPLACE FUNCTION current_org_id() RETURNS uuid AS $$
            BEGIN
                RETURN nullif(current_setting('app.org_id', true), '')::uuid;
            EXCEPTION WHEN others THEN
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql STABLE;

            CREATE OR REPLACE FUNCTION is_platform_admin() RETURNS boolean AS $$
            BEGIN
                RETURN coalesce(current_setting('app.is_platform_admin', true), 'off') = 'on';
            END;
            $$ LANGUAGE plpgsql STABLE;

            -- Defined here, not only in the later RLS migration, because the
            -- question and review tables (February migrations) already use
            -- them in their policies. On an empty database those migrations
            -- failed with "function rls_bypass() does not exist"; existing
            -- databases only worked because migrate:fresh drops tables but
            -- leaves functions behind. The RLS migration redefines both with
            -- CREATE OR REPLACE, identically.
            CREATE OR REPLACE FUNCTION current_user_id() RETURNS uuid AS $$
            BEGIN
                RETURN nullif(current_setting('app.user_id', true), '')::uuid;
            EXCEPTION WHEN others THEN
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql STABLE;

            CREATE OR REPLACE FUNCTION rls_bypass() RETURNS boolean AS $$
            BEGIN
                RETURN coalesce(current_setting('app.bypass_rls', true), 'off') = 'on';
            END;
            $$ LANGUAGE plpgsql STABLE;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP FUNCTION IF EXISTS is_platform_admin();
            DROP FUNCTION IF EXISTS current_org_id();
            DROP FUNCTION IF EXISTS touch_updated_at();
            DROP FUNCTION IF EXISTS uuid_generate_v7();
        SQL);
    }
};
