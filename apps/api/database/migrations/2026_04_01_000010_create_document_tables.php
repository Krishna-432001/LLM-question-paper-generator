<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Generated documents.
 *
 * The LLM never produces a PDF. Structured output → validation → database →
 * template → renderer → object storage. That indirection is what makes
 * pagination exact, re-renders byte-identical, and a paper reproducible years
 * later from stored data, long after the model that wrote the questions is
 * gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE document_type AS ENUM (
                    'question_paper','answer_key','explanations','study_plan',
                    'prediction_report','topic_analysis','result_report','ca_digest','custom'
                );
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            DO $$ BEGIN
                CREATE TYPE document_status AS ENUM ('pending','rendering','ready','stale','failed');
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            CREATE TABLE IF NOT EXISTS document_templates (
                id            uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                code          text NOT NULL,
                version       int NOT NULL,
                type          document_type NOT NULL,
                engine        text NOT NULL DEFAULT 'mpdf',
                template_body text NOT NULL DEFAULT '',
                schema        jsonb NOT NULL DEFAULT '{}'::jsonb,
                is_active     boolean NOT NULL DEFAULT true,
                created_at    timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT document_templates_unique UNIQUE (code, version)
            );

            CREATE TABLE IF NOT EXISTS generated_documents (
                id                uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id   uuid REFERENCES organizations(id) ON DELETE CASCADE,
                user_id           uuid REFERENCES users(id) ON DELETE SET NULL,
                type              document_type NOT NULL,
                documentable_type text NOT NULL,
                documentable_id   uuid NOT NULL,
                language_id       uuid NOT NULL REFERENCES languages(id),
                template_id       uuid REFERENCES document_templates(id) ON DELETE SET NULL,
                title             text NOT NULL,
                -- Printed on the page so a student can always match a key to
                -- the paper it belongs to.
                document_code     text NOT NULL,
                storage_disk      text NOT NULL DEFAULT 'local',
                storage_path      text,
                file_size_bytes   bigint,
                page_count        int,
                -- sha256 over template version + locale + branding + the
                -- ordered question versions. Makes "has anything changed?" a
                -- free comparison, so 10,000 identical requests resolve to one
                -- stored object.
                content_hash      text NOT NULL,
                render_payload    jsonb NOT NULL DEFAULT '{}'::jsonb,
                status            document_status NOT NULL DEFAULT 'pending',
                download_count    int NOT NULL DEFAULT 0,
                error_message     text,
                expires_at        timestamptz,
                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now()
            );

            CREATE UNIQUE INDEX IF NOT EXISTS generated_documents_reuse
                ON generated_documents (documentable_type, documentable_id, type, language_id, content_hash);
            CREATE INDEX IF NOT EXISTS generated_documents_owner
                ON generated_documents (organization_id, user_id, created_at DESC);
            CREATE TRIGGER generated_documents_touch BEFORE UPDATE ON generated_documents
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

            CREATE TABLE IF NOT EXISTS document_shares (
                id                    uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                generated_document_id uuid NOT NULL REFERENCES generated_documents(id) ON DELETE CASCADE,
                token                 text NOT NULL UNIQUE,
                created_by            uuid REFERENCES users(id) ON DELETE SET NULL,
                password_hash         text,
                max_downloads         int,
                download_count        int NOT NULL DEFAULT 0,
                expires_at            timestamptz,
                revoked_at            timestamptz,
                created_at            timestamptz NOT NULL DEFAULT now()
            );

            ALTER TABLE generated_documents ENABLE ROW LEVEL SECURITY;
            ALTER TABLE generated_documents FORCE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS generated_documents_tenant ON generated_documents;
            CREATE POLICY generated_documents_tenant ON generated_documents
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
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS document_shares;
            DROP TABLE IF EXISTS generated_documents CASCADE;
            DROP TABLE IF EXISTS document_templates CASCADE;
            DROP TYPE IF EXISTS document_status, document_type;
        SQL);
    }
};
