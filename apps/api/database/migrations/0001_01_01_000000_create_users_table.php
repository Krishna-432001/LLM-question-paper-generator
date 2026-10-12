<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE users (
                id                    uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                email                 citext NOT NULL,
                phone                 text,                 -- encrypted cast at app layer
                name                  text NOT NULL,
                password              text,                 -- null for OAuth-only accounts
                email_verified_at     timestamptz,
                phone_verified_at     timestamptz,
                mfa_secret            text,                 -- encrypted cast at app layer
                mfa_enabled_at        timestamptz,
                locale                text NOT NULL DEFAULT 'en',
                preferred_language_id uuid,                 -- FK added with languages
                timezone              text NOT NULL DEFAULT 'Asia/Kolkata',
                is_platform_admin     boolean NOT NULL DEFAULT false,
                last_login_at         timestamptz,
                remember_token        varchar(100),
                created_at            timestamptz NOT NULL DEFAULT now(),
                updated_at            timestamptz NOT NULL DEFAULT now(),
                deleted_at            timestamptz
            );
            CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL;
            CREATE TRIGGER users_touch BEFORE UPDATE ON users
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

            CREATE TABLE password_reset_tokens (
                email      citext PRIMARY KEY,
                token      text NOT NULL,
                created_at timestamptz
            );

            CREATE TABLE sessions (
                id            varchar(255) PRIMARY KEY,
                user_id       uuid REFERENCES users(id) ON DELETE CASCADE,
                ip_address    varchar(45),
                user_agent    text,
                payload       text NOT NULL,
                last_activity integer NOT NULL
            );
            CREATE INDEX sessions_user_id_index ON sessions (user_id);
            CREATE INDEX sessions_last_activity_index ON sessions (last_activity);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS sessions;
            DROP TABLE IF EXISTS password_reset_tokens;
            DROP TABLE IF EXISTS users CASCADE;
        SQL);
    }
};
