<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE exams (
                id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id uuid REFERENCES organizations(id) ON DELETE CASCADE,
                code            text NOT NULL,
                name            text NOT NULL,
                conducting_body text,
                country         char(2) NOT NULL DEFAULT 'IN',
                state           text,
                category        text,
                level           text,
                description     text,
                is_active       boolean NOT NULL DEFAULT true,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now()
            );
            -- organization_id IS NULL = platform exam, visible to every tenant.
            -- Two partial unique indexes because NULL never equals NULL in SQL.
            CREATE UNIQUE INDEX exams_platform_code_unique
                ON exams (code) WHERE organization_id IS NULL;
            CREATE UNIQUE INDEX exams_org_code_unique
                ON exams (organization_id, code) WHERE organization_id IS NOT NULL;
            CREATE INDEX exams_org_idx ON exams (organization_id);
            CREATE TRIGGER exams_touch BEFORE UPDATE ON exams
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

            CREATE TABLE exam_versions (
                id                uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                exam_id           uuid NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
                version_label     text NOT NULL,
                notification_date date,
                exam_date         date,
                status            text NOT NULL DEFAULT 'draft',
                source_id         uuid,                      -- FK added with sources
                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT exam_versions_status_check
                    CHECK (status IN ('draft','active','superseded','archived')),
                CONSTRAINT exam_versions_unique UNIQUE (exam_id, version_label)
            );

            CREATE TABLE exam_stages (
                id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                exam_version_id uuid NOT NULL REFERENCES exam_versions(id) ON DELETE CASCADE,
                name            text NOT NULL,
                sequence        int NOT NULL,
                is_qualifying   boolean NOT NULL DEFAULT false,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT exam_stages_unique UNIQUE (exam_version_id, sequence)
            );

            CREATE TABLE exam_papers (
                id               uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                exam_stage_id    uuid NOT NULL REFERENCES exam_stages(id) ON DELETE CASCADE,
                name             text NOT NULL,
                sequence         int NOT NULL,
                duration_minutes int,
                total_questions  int,
                total_marks      numeric(8,2),
                negative_marking numeric(4,2) NOT NULL DEFAULT 0,
                question_type    text NOT NULL DEFAULT 'mcq4',
                created_at       timestamptz NOT NULL DEFAULT now(),
                updated_at       timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT exam_papers_unique UNIQUE (exam_stage_id, sequence)
            );

            CREATE TABLE exam_subjects (
                id             uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                exam_paper_id  uuid NOT NULL REFERENCES exam_papers(id) ON DELETE CASCADE,
                name           text NOT NULL,
                code           text,
                weight         numeric(5,4) NOT NULL DEFAULT 0,
                question_count int,
                is_optional    boolean NOT NULL DEFAULT false,
                optional_group text,
                sequence       int NOT NULL DEFAULT 0,
                created_at     timestamptz NOT NULL DEFAULT now(),
                updated_at     timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX exam_subjects_paper_idx ON exam_subjects (exam_paper_id);

            CREATE TABLE exam_languages (
                exam_version_id uuid NOT NULL REFERENCES exam_versions(id) ON DELETE CASCADE,
                language_id     uuid NOT NULL REFERENCES languages(id),
                is_default      boolean NOT NULL DEFAULT false,
                PRIMARY KEY (exam_version_id, language_id)
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS exam_languages;
            DROP TABLE IF EXISTS exam_subjects CASCADE;
            DROP TABLE IF EXISTS exam_papers CASCADE;
            DROP TABLE IF EXISTS exam_stages CASCADE;
            DROP TABLE IF EXISTS exam_versions CASCADE;
            DROP TABLE IF EXISTS exams CASCADE;
        SQL);
    }
};
