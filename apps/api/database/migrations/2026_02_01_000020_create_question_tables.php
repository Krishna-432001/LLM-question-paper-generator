<?php

declare(strict_types=1);

use App\Support\Database\VectorSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The master question bank — the platform's actual asset.
 *
 * Design invariants enforced here in the database, not just in application code:
 *   1. A question carries no text. All renderings, including the master
 *      language, live in question_translations. The asymmetry of "English in
 *      questions, other languages elsewhere" always rots.
 *   2. The correct answer lives once, on question_options, and is
 *      language-neutral. No translation can disagree with the answer key.
 *   3. An APPROVED question must cite a source chunk. Enforced by trigger.
 *   4. One logical question maps to many exams. Never duplicated per exam.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE question_status AS ENUM (
                    'DRAFT','AI_GENERATED','AI_VALIDATED','PENDING_REVIEW',
                    'APPROVED','REJECTED','REVISION_REQUIRED','RETIRED'
                );
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            DO $$ BEGIN
                CREATE TYPE difficulty_level AS ENUM ('easy','medium','hard');
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            DO $$ BEGIN
                CREATE TYPE question_format AS ENUM (
                    'mcq4','mcq5','true_false','assertion_reason','match_the_following',
                    'statement_based','fill_blank','short_answer','descriptive','numerical'
                );
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            DO $$ BEGIN
                CREATE TYPE question_origin AS ENUM (
                    'ai_generated','textbook_exercise','previous_paper','human_authored','imported'
                );
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            DO $$ BEGIN
                CREATE TYPE translation_status AS ENUM (
                    'MACHINE','AI_VALIDATED','NEEDS_REVIEW','HUMAN_APPROVED','STALE','REJECTED'
                );
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            CREATE TABLE IF NOT EXISTS questions (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id     uuid REFERENCES organizations(id) ON DELETE CASCADE,
                format              question_format NOT NULL DEFAULT 'mcq4',
                origin              question_origin NOT NULL DEFAULT 'ai_generated',
                status              question_status NOT NULL DEFAULT 'AI_GENERATED',
                master_language_id  uuid NOT NULL REFERENCES languages(id),
                difficulty          difficulty_level NOT NULL,
                difficulty_score    numeric(5,4),
                bloom_level         text,
                canonical_topic_id  uuid REFERENCES canonical_topics(id),
                quality_score       numeric(5,2),
                ai_confidence       numeric(5,4),
                p_value             numeric(5,4),
                discrimination      numeric(5,4),
                times_served        bigint NOT NULL DEFAULT 0,
                times_attempted     bigint NOT NULL DEFAULT 0,
                is_time_sensitive   boolean NOT NULL DEFAULT false,
                validity_from       date,
                validity_to         date,
                current_version     int NOT NULL DEFAULT 1,
                generated_by_job_id uuid,
                created_by          uuid REFERENCES users(id) ON DELETE SET NULL,
                approved_by         uuid REFERENCES users(id) ON DELETE SET NULL,
                approved_at         timestamptz,
                retired_reason      text,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS questions_status_topic ON questions (status, canonical_topic_id, difficulty);
            CREATE INDEX IF NOT EXISTS questions_org ON questions (organization_id);
            CREATE INDEX IF NOT EXISTS questions_approved_quality
                ON questions (quality_score DESC) WHERE status = 'APPROVED';
            CREATE TRIGGER questions_touch BEFORE UPDATE ON questions
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

            CREATE TABLE IF NOT EXISTS question_options (
                id          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                question_id uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                option_key  char(1) NOT NULL,
                position    int NOT NULL,
                is_correct  boolean NOT NULL DEFAULT false,
                CONSTRAINT question_options_unique UNIQUE (question_id, option_key)
            );

            -- Exactly one correct option. DEFERRABLE so a question and its four
            -- options can be inserted in any order inside one transaction.
            CREATE OR REPLACE FUNCTION assert_single_correct_option() RETURNS trigger AS $$
            DECLARE
                qid uuid := COALESCE(NEW.question_id, OLD.question_id);
                n   int;
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM questions WHERE id = qid) THEN
                    RETURN NULL;   -- question deleted; options cascaded
                END IF;
                SELECT count(*) INTO n FROM question_options
                    WHERE question_id = qid AND is_correct;
                IF n <> 1 THEN
                    RAISE EXCEPTION 'question % must have exactly one correct option (found %)', qid, n;
                END IF;
                RETURN NULL;
            END $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_single_correct ON question_options;
            CREATE CONSTRAINT TRIGGER trg_single_correct
                AFTER INSERT OR UPDATE OR DELETE ON question_options
                DEFERRABLE INITIALLY DEFERRED
                FOR EACH ROW EXECUTE FUNCTION assert_single_correct_option();

            CREATE TABLE IF NOT EXISTS question_translations (
                id                      uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                question_id             uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                language_id             uuid NOT NULL REFERENCES languages(id),
                is_master               boolean NOT NULL DEFAULT false,
                source_language_id      uuid REFERENCES languages(id),
                source_question_version int NOT NULL DEFAULT 1,
                question_text           text NOT NULL,
                option_a text, option_b text, option_c text, option_d text, option_e text,
                explanation             text,
                translation_version     int NOT NULL DEFAULT 1,
                translation_status      translation_status NOT NULL DEFAULT 'MACHINE',
                quality_score           numeric(5,2),
                validation_report       jsonb NOT NULL DEFAULT '{}'::jsonb,
                review_status           text NOT NULL DEFAULT 'pending',
                reviewed_by             uuid REFERENCES users(id) ON DELETE SET NULL,
                reviewed_at             timestamptz,
                translated_by_job_id    uuid,
                fts                     tsvector GENERATED ALWAYS AS
                                            (to_tsvector('simple', coalesce(question_text,''))) STORED,
                created_at              timestamptz NOT NULL DEFAULT now(),
                updated_at              timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT question_translations_unique
                    UNIQUE (question_id, language_id, translation_version)
            );
            CREATE UNIQUE INDEX IF NOT EXISTS one_master_per_question
                ON question_translations (question_id) WHERE is_master;
            CREATE INDEX IF NOT EXISTS qtrans_fts ON question_translations USING gin (fts);
            CREATE INDEX IF NOT EXISTS qtrans_trgm
                ON question_translations USING gin (question_text gin_trgm_ops);
            CREATE INDEX IF NOT EXISTS qtrans_lang ON question_translations (language_id, translation_status);

            -- The trust backbone: no citation, no publication.
            CREATE TABLE IF NOT EXISTS question_sources (
                id               uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                question_id      uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                source_id        uuid NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
                book_id          uuid REFERENCES books(id) ON DELETE SET NULL,
                book_lesson_id   uuid REFERENCES book_lessons(id) ON DELETE SET NULL,
                source_chunk_id  uuid REFERENCES source_chunks(id) ON DELETE SET NULL,
                page_start       int,
                page_end         int,
                supporting_quote text,
                relevance        numeric(5,4) NOT NULL DEFAULT 1.0,
                is_primary       boolean NOT NULL DEFAULT true,
                created_at       timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS question_sources_q ON question_sources (question_id);

            CREATE OR REPLACE FUNCTION assert_question_has_source() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'APPROVED'
                   AND NEW.origin NOT IN ('human_authored','previous_paper')
                   AND NOT EXISTS (SELECT 1 FROM question_sources WHERE question_id = NEW.id)
                THEN
                    RAISE EXCEPTION 'question % cannot be APPROVED without a source citation', NEW.id;
                END IF;
                RETURN NEW;
            END $$ LANGUAGE plpgsql;

            DROP TRIGGER IF EXISTS trg_question_source ON questions;
            CREATE TRIGGER trg_question_source BEFORE UPDATE OF status ON questions
                FOR EACH ROW EXECUTE FUNCTION assert_question_has_source();

            -- One question, many exams. This table is why the bank does not
            -- multiply as exams are added.
            CREATE TABLE IF NOT EXISTS question_exam_mappings (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                question_id         uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                exam_id             uuid NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
                exam_subject_id     uuid REFERENCES exam_subjects(id) ON DELETE SET NULL,
                syllabus_version_id uuid REFERENCES syllabus_versions(id) ON DELETE SET NULL,
                suitability         numeric(5,4) NOT NULL DEFAULT 1.0,
                difficulty_for_exam difficulty_level,
                mapped_by           text NOT NULL DEFAULT 'ai',
                verified_by         uuid REFERENCES users(id) ON DELETE SET NULL,
                is_active           boolean NOT NULL DEFAULT true,
                CONSTRAINT question_exam_mappings_unique
                    UNIQUE (question_id, exam_id, syllabus_version_id)
            );
            CREATE INDEX IF NOT EXISTS qem_exam ON question_exam_mappings (exam_id, is_active);

            CREATE TABLE IF NOT EXISTS question_topic_mappings (
                id                 uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                question_id        uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                exam_topic_id      uuid REFERENCES exam_topics(id) ON DELETE CASCADE,
                canonical_topic_id uuid REFERENCES canonical_topics(id) ON DELETE CASCADE,
                is_primary         boolean NOT NULL DEFAULT true,
                confidence         numeric(5,4) NOT NULL DEFAULT 1.0
            );
            CREATE UNIQUE INDEX IF NOT EXISTS qtm_unique
                ON question_topic_mappings (question_id, coalesce(exam_topic_id, canonical_topic_id));
            CREATE INDEX IF NOT EXISTS qtm_topic ON question_topic_mappings (canonical_topic_id);

            -- Rejected questions are retained, never deleted: they tune the
            -- overgeneration factor, train reviewers, and expose bad OCR pages.
            CREATE TABLE IF NOT EXISTS question_rejections (
                id          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                question_id uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                stage       text NOT NULL,
                code        text NOT NULL,
                detail      jsonb NOT NULL DEFAULT '{}'::jsonb,
                actor       text NOT NULL DEFAULT 'system',
                created_at  timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS question_rejections_code ON question_rejections (code, created_at DESC);

            CREATE TABLE IF NOT EXISTS question_validations (
                id               uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                question_id      uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                check_code       text NOT NULL,
                passed           boolean NOT NULL,
                score            numeric(5,4),
                detail           jsonb NOT NULL DEFAULT '{}'::jsonb,
                ai_invocation_id uuid,
                created_at       timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS question_validations_q ON question_validations (question_id);

            CREATE TABLE IF NOT EXISTS question_versions (
                id            uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                question_id   uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                version       int NOT NULL,
                snapshot      jsonb NOT NULL,
                change_type   text NOT NULL,
                change_reason text,
                changed_by    uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at    timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT question_versions_unique UNIQUE (question_id, version)
            );

            CREATE TABLE IF NOT EXISTS question_relations (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                question_id         uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                related_question_id uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                relation            text NOT NULL,
                similarity          numeric(5,4),
                detected_by         text NOT NULL DEFAULT 'vector',
                CONSTRAINT question_relations_not_self CHECK (question_id <> related_question_id),
                CONSTRAINT question_relations_unique UNIQUE (question_id, related_question_id, relation)
            );

            -- Overgeneration factors and thresholds are DATA and are auto-tuned
            -- nightly from observed acceptance rates.
            CREATE TABLE IF NOT EXISTS generation_policies (
                id                     uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id        uuid REFERENCES organizations(id) ON DELETE CASCADE,
                exam_id                uuid REFERENCES exams(id) ON DELETE CASCADE,
                exam_subject_id        uuid REFERENCES exam_subjects(id) ON DELETE CASCADE,
                overgeneration_factor  numeric(4,2) NOT NULL DEFAULT 1.70,
                batch_size             int NOT NULL DEFAULT 25,
                auto_approve_threshold numeric(5,2) NOT NULL DEFAULT 85,
                review_threshold       numeric(5,2) NOT NULL DEFAULT 60,
                allow_auto_approve     boolean NOT NULL DEFAULT false,
                dup_hard_threshold     numeric(5,4) NOT NULL DEFAULT 0.93,
                dup_grey_threshold     numeric(5,4) NOT NULL DEFAULT 0.86,
                difficulty_mix         jsonb NOT NULL DEFAULT '{"easy":0.4,"medium":0.4,"hard":0.2}',
                created_at             timestamptz NOT NULL DEFAULT now(),
                updated_at             timestamptz NOT NULL DEFAULT now()
            );
        SQL);

        $vectorType = VectorSupport::columnType();

        DB::unprepared(<<<SQL
            CREATE TABLE IF NOT EXISTS question_embeddings (
                id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                question_id     uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                language_id     uuid NOT NULL REFERENCES languages(id),
                embedding_model text NOT NULL DEFAULT 'multilingual-e5-large',
                embedding       {$vectorType} NOT NULL,
                created_at      timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT question_embeddings_unique
                    UNIQUE (question_id, language_id, embedding_model)
            );
        SQL);

        if (VectorSupport::isAvailable()) {
            DB::unprepared(<<<'SQL'
                CREATE INDEX IF NOT EXISTS question_embeddings_hnsw
                    ON question_embeddings USING hnsw (embedding vector_cosine_ops)
                    WITH (m = 16, ef_construction = 64);
            SQL);
        }

        // Questions are platform-shared content: NULL org = readable by all.
        DB::unprepared(<<<'SQL'
            ALTER TABLE questions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE questions FORCE ROW LEVEL SECURITY;

            DROP POLICY IF EXISTS questions_tenant_read ON questions;
            CREATE POLICY questions_tenant_read ON questions FOR SELECT
            USING (
                rls_bypass() OR is_platform_admin()
                OR organization_id IS NULL
                OR organization_id = current_org_id()
            );

            DROP POLICY IF EXISTS questions_tenant_write ON questions;
            CREATE POLICY questions_tenant_write ON questions
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

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS question_embeddings, generation_policies, question_relations,
                question_versions, question_validations, question_rejections,
                question_topic_mappings, question_exam_mappings, question_sources,
                question_translations, question_options CASCADE;
            DROP TABLE IF EXISTS questions CASCADE;
            DROP FUNCTION IF EXISTS assert_question_has_source();
            DROP FUNCTION IF EXISTS assert_single_correct_option();
            DROP TYPE IF EXISTS translation_status, question_origin, question_format,
                difficulty_level, question_status;
        SQL);
    }
};
