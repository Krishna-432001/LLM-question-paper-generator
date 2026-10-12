<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- CREATE TYPE has no IF NOT EXISTS. Types also survive
            -- `migrate:fresh` (which drops tables, not types), so every type
            -- creation is guarded to keep migrations re-runnable.
            DO $$ BEGIN
                CREATE TYPE org_type AS ENUM (
                    'individual','coaching_institute','school','enterprise','platform'
                );
            EXCEPTION WHEN duplicate_object THEN NULL;
            END $$;

            CREATE TABLE organizations (
                id            uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                name          text NOT NULL,
                slug          citext NOT NULL UNIQUE,
                type          org_type NOT NULL DEFAULT 'individual',
                owner_user_id uuid REFERENCES users(id) ON DELETE SET NULL,
                branding      jsonb NOT NULL DEFAULT '{}'::jsonb,
                settings      jsonb NOT NULL DEFAULT '{}'::jsonb,
                seat_limit    int,
                is_active     boolean NOT NULL DEFAULT true,
                trial_ends_at timestamptz,
                created_at    timestamptz NOT NULL DEFAULT now(),
                updated_at    timestamptz NOT NULL DEFAULT now(),
                deleted_at    timestamptz
            );
            CREATE TRIGGER organizations_touch BEFORE UPDATE ON organizations
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

            CREATE TABLE organization_users (
                id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
                user_id         uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                status          text NOT NULL DEFAULT 'active',
                is_default      boolean NOT NULL DEFAULT false,
                invited_by      uuid REFERENCES users(id) ON DELETE SET NULL,
                joined_at       timestamptz NOT NULL DEFAULT now(),
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT organization_users_status_check
                    CHECK (status IN ('active','invited','suspended')),
                CONSTRAINT organization_users_unique UNIQUE (organization_id, user_id)
            );
            CREATE INDEX organization_users_user_idx ON organization_users (user_id);
            -- Exactly one default organization per user drives the implicit tenant.
            CREATE UNIQUE INDEX organization_users_one_default
                ON organization_users (user_id) WHERE is_default;
            CREATE TRIGGER organization_users_touch BEFORE UPDATE ON organization_users
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS organization_users;
            DROP TABLE IF EXISTS organizations CASCADE;
            DROP TYPE IF EXISTS org_type;
        SQL);
    }
};
