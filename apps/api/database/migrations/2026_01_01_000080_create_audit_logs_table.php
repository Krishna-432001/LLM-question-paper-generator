<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE audit_logs (
                id              uuid NOT NULL DEFAULT uuid_generate_v7(),
                organization_id uuid,
                actor_user_id   uuid,
                actor_role      text,
                action          text NOT NULL,
                auditable_type  text,
                auditable_id    uuid,
                changes         jsonb,          -- {before: {...}, after: {...}}
                ip              inet,
                user_agent      text,
                request_id      text,
                created_at      timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at);

            CREATE INDEX audit_logs_org_idx    ON audit_logs (organization_id, created_at DESC);
            CREATE INDEX audit_logs_actor_idx  ON audit_logs (actor_user_id, created_at DESC);
            CREATE INDEX audit_logs_target_idx ON audit_logs (auditable_type, auditable_id);
        SQL);

        \App\Support\Database\PartitionManager::ensureMonthlyRange('audit_logs', -1, 3);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS audit_logs CASCADE');
    }
};
