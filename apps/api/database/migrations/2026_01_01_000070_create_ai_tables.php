<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE job_status AS ENUM (
                    'QUEUED','PROCESSING','VALIDATING','COMPLETED',
                    'FAILED','CANCELLED','RETRY_SCHEDULED'
                );
            EXCEPTION WHEN duplicate_object THEN NULL;
            END $$;

            CREATE TABLE ai_models (
                id                          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                provider                    text NOT NULL,
                model_key                   text NOT NULL UNIQUE,
                display_name                text NOT NULL,
                tier                        text NOT NULL,
                context_window              int,
                input_price_per_mtok        numeric(12,4),
                output_price_per_mtok       numeric(12,4),
                cached_input_price_per_mtok numeric(12,4),
                supports_structured_output  boolean NOT NULL DEFAULT true,
                is_active                   boolean NOT NULL DEFAULT true,
                created_at                  timestamptz NOT NULL DEFAULT now(),
                updated_at                  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ai_models_tier_check
                    CHECK (tier IN ('premium','mid','cheap','embedding'))
            );

            CREATE TABLE prompt_templates (
                id          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                name        text NOT NULL UNIQUE,
                category    text NOT NULL,
                description text,
                created_at  timestamptz NOT NULL DEFAULT now(),
                updated_at  timestamptz NOT NULL DEFAULT now()
            );

            -- Prompt versions are immutable once they leave draft. A running job
            -- pins the version it started with, so a mid-flight release never
            -- mixes prompt behaviour within one book.
            CREATE TABLE prompt_versions (
                id                 uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                prompt_template_id uuid NOT NULL REFERENCES prompt_templates(id) ON DELETE CASCADE,
                version            int NOT NULL,
                ai_model_id        uuid NOT NULL REFERENCES ai_models(id),
                system_prompt      text NOT NULL,
                user_prompt        text NOT NULL,
                output_schema      jsonb NOT NULL,
                params             jsonb NOT NULL DEFAULT '{}'::jsonb,
                cache_segments     jsonb NOT NULL DEFAULT '{}'::jsonb,
                status             text NOT NULL DEFAULT 'draft',
                rollout_percent    int NOT NULL DEFAULT 0,
                changelog          text,
                created_by         uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at         timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT prompt_versions_status_check CHECK (status IN
                    ('draft','canary','active','deprecated','archived')),
                CONSTRAINT prompt_versions_unique UNIQUE (prompt_template_id, version)
            );
            CREATE UNIQUE INDEX prompt_versions_one_active
                ON prompt_versions (prompt_template_id) WHERE status = 'active';

            CREATE TABLE prompt_evaluations (
                id                uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                prompt_version_id uuid NOT NULL REFERENCES prompt_versions(id) ON DELETE CASCADE,
                golden_set        text NOT NULL,
                metrics           jsonb NOT NULL,
                passed            boolean NOT NULL,
                evaluated_at      timestamptz NOT NULL DEFAULT now()
            );

            -- The single job ledger for ALL async work. Laravel's jobs table is
            -- transport; this is the user-visible, costed, resumable record.
            CREATE TABLE ai_jobs (
                id                 uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                parent_job_id      uuid REFERENCES ai_jobs(id) ON DELETE CASCADE,
                organization_id    uuid REFERENCES organizations(id) ON DELETE CASCADE,
                user_id            uuid REFERENCES users(id) ON DELETE SET NULL,
                type               text NOT NULL,
                queue              text NOT NULL,
                status             job_status NOT NULL DEFAULT 'QUEUED',
                progress           int NOT NULL DEFAULT 0,
                progress_message   text,
                subject_type       text,
                subject_id         uuid,
                input              jsonb NOT NULL DEFAULT '{}'::jsonb,
                output             jsonb,
                prompt_version_ids uuid[] NOT NULL DEFAULT '{}',
                idempotency_key    text UNIQUE,
                attempts           int NOT NULL DEFAULT 0,
                max_attempts       int NOT NULL DEFAULT 3,
                error_code         text,
                error_message      text,
                error_trace        text,
                input_tokens       bigint NOT NULL DEFAULT 0,
                output_tokens      bigint NOT NULL DEFAULT 0,
                cost_inr           numeric(12,4) NOT NULL DEFAULT 0,
                queued_at          timestamptz NOT NULL DEFAULT now(),
                started_at         timestamptz,
                completed_at       timestamptz,
                cancelled_at       timestamptz,
                duration_ms        int,
                created_at         timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT ai_jobs_progress_check CHECK (progress BETWEEN 0 AND 100)
            );
            CREATE INDEX ai_jobs_status_idx  ON ai_jobs (status, type, queued_at);
            CREATE INDEX ai_jobs_org_idx     ON ai_jobs (organization_id, created_at DESC);
            CREATE INDEX ai_jobs_subject_idx ON ai_jobs (subject_type, subject_id);
            CREATE INDEX ai_jobs_parent_idx  ON ai_jobs (parent_job_id);
            CREATE TRIGGER ai_jobs_touch BEFORE UPDATE ON ai_jobs
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

            -- Typed views so the spec's job tables exist without splitting the ledger.
            CREATE VIEW ocr_jobs         AS SELECT * FROM ai_jobs WHERE type = 'ocr';
            CREATE VIEW embedding_jobs   AS SELECT * FROM ai_jobs WHERE type = 'embedding';
            CREATE VIEW generation_jobs  AS SELECT * FROM ai_jobs WHERE type = 'generation';
            CREATE VIEW validation_jobs  AS SELECT * FROM ai_jobs WHERE type = 'validation';
            CREATE VIEW translation_jobs AS SELECT * FROM ai_jobs WHERE type = 'translation';
            CREATE VIEW pdf_jobs         AS SELECT * FROM ai_jobs WHERE type = 'pdf';

            -- One row per model call. This is what makes cost attributable and
            -- every generated question traceable to its exact prompt.
            CREATE TABLE ai_invocations (
                id                  uuid NOT NULL DEFAULT uuid_generate_v7(),
                ai_job_id           uuid,
                organization_id     uuid,
                user_id             uuid,
                prompt_version_id   uuid,
                ai_model_id         uuid,
                operation           text NOT NULL,
                input_tokens        int NOT NULL DEFAULT 0,
                output_tokens       int NOT NULL DEFAULT 0,
                cached_input_tokens int NOT NULL DEFAULT 0,
                cost_inr            numeric(12,6) NOT NULL DEFAULT 0,
                latency_ms          int,
                cache_hit           boolean NOT NULL DEFAULT false,
                degraded            boolean NOT NULL DEFAULT false,
                finish_reason       text,
                request_hash        text NOT NULL,
                response_hash       text,
                request_snapshot    jsonb,        -- pruned after 30 days
                error               text,
                created_at          timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, created_at)
            ) PARTITION BY RANGE (created_at);
            CREATE INDEX ai_invocations_org_idx  ON ai_invocations (organization_id, created_at DESC);
            CREATE INDEX ai_invocations_job_idx  ON ai_invocations (ai_job_id);
            CREATE INDEX ai_invocations_hash_idx ON ai_invocations (request_hash);

            CREATE TABLE ai_response_cache (
                request_hash text PRIMARY KEY,
                model_key    text NOT NULL,
                operation    text NOT NULL,
                response     jsonb NOT NULL,
                hit_count    int NOT NULL DEFAULT 0,
                created_at   timestamptz NOT NULL DEFAULT now(),
                expires_at   timestamptz
            );
            CREATE INDEX ai_response_cache_expiry ON ai_response_cache (expires_at);
        SQL);

        \App\Support\Database\PartitionManager::ensureMonthlyRange('ai_invocations', -1, 3);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS ai_response_cache;
            DROP TABLE IF EXISTS ai_invocations CASCADE;
            DROP VIEW IF EXISTS pdf_jobs, translation_jobs, validation_jobs,
                                generation_jobs, embedding_jobs, ocr_jobs;
            DROP TABLE IF EXISTS ai_jobs CASCADE;
            DROP TABLE IF EXISTS prompt_evaluations;
            DROP TABLE IF EXISTS prompt_versions CASCADE;
            DROP TABLE IF EXISTS prompt_templates CASCADE;
            DROP TABLE IF EXISTS ai_models CASCADE;
            DROP TYPE IF EXISTS job_status;
        SQL);
    }
};
