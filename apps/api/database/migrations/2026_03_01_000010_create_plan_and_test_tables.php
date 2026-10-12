<?php

declare(strict_types=1);

use App\Support\Database\PartitionManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 — study plans, tests, attempts.
 *
 * Note what is absent: no LLM touches any of this. Plans are produced by a
 * deterministic scheduler and tests by a constrained selector, both reading
 * data the model helped build in Phase 2. That is what makes a plan free,
 * instant, reproducible and provably complete — and it is why a student
 * clicking "generate weekly test" costs nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE plan_mode AS ENUM
                    ('daily','three_day','weekly','monthly','model_test','custom');
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            DO $$ BEGIN
                CREATE TYPE plan_status AS ENUM
                    ('draft','generating','active','completed','archived','failed');
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            DO $$ BEGIN
                CREATE TYPE test_type AS ENUM ('daily','three_day','weekly','monthly',
                    'model','prediction','topic_practice','custom','diagnostic');
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            DO $$ BEGIN
                CREATE TYPE test_status AS ENUM
                    ('draft','assembling','ready','published','archived','failed');
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            -- The five plan modes are parameters, not five code paths.
            CREATE TABLE IF NOT EXISTS plan_blueprints (
                id           uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                mode         plan_mode NOT NULL,
                name         text NOT NULL,
                horizon_days int NOT NULL,
                structure    jsonb NOT NULL DEFAULT '{}'::jsonb,
                test_config  jsonb NOT NULL DEFAULT '{}'::jsonb,
                documents    text[] NOT NULL DEFAULT '{}',
                is_default   boolean NOT NULL DEFAULT false,
                created_at   timestamptz NOT NULL DEFAULT now(),
                updated_at   timestamptz NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX IF NOT EXISTS plan_blueprints_default
                ON plan_blueprints (mode) WHERE is_default;

            CREATE TABLE IF NOT EXISTS study_plans (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id     uuid NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
                user_id             uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                parent_plan_id      uuid REFERENCES study_plans(id) ON DELETE SET NULL,
                exam_id             uuid NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
                -- Plans reference a syllabus VERSION, never a bare exam, so a
                -- student preparing under the 2025 notification keeps their
                -- plan when the 2026 syllabus lands.
                syllabus_version_id uuid NOT NULL REFERENCES syllabus_versions(id),
                plan_blueprint_id   uuid REFERENCES plan_blueprints(id),
                mode                plan_mode NOT NULL,
                language_id         uuid NOT NULL REFERENCES languages(id),
                start_date          date NOT NULL,
                end_date            date NOT NULL,
                exam_date           date,
                hours_per_day       numeric(4,2) NOT NULL DEFAULT 4,
                off_days            int[] NOT NULL DEFAULT '{}',
                status              plan_status NOT NULL DEFAULT 'draft',
                revision            int NOT NULL DEFAULT 1,
                coverage_percent    numeric(5,2),
                -- When capacity < demand the dropped topics are named, never
                -- silently omitted.
                dropped_topics      jsonb NOT NULL DEFAULT '[]'::jsonb,
                generated_by_job_id uuid,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS study_plans_user ON study_plans (user_id, mode, status);

            CREATE TABLE IF NOT EXISTS study_plan_days (
                id             uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                study_plan_id  uuid NOT NULL REFERENCES study_plans(id) ON DELETE CASCADE,
                day_number     int NOT NULL,
                plan_date      date NOT NULL,
                day_role       text NOT NULL,
                target_minutes int NOT NULL DEFAULT 0,
                -- Past days are history, not a projection: replanning rewrites
                -- only future days.
                is_locked      boolean NOT NULL DEFAULT false,
                completed_at   timestamptz,
                completion_pct numeric(5,2) NOT NULL DEFAULT 0,
                test_id        uuid,
                CONSTRAINT study_plan_days_unique UNIQUE (study_plan_id, day_number)
            );

            CREATE TABLE IF NOT EXISTS study_plan_items (
                id                   uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                study_plan_day_id    uuid NOT NULL REFERENCES study_plan_days(id) ON DELETE CASCADE,
                item_type            text NOT NULL,
                exam_topic_id        uuid REFERENCES exam_topics(id) ON DELETE SET NULL,
                canonical_topic_id   uuid REFERENCES canonical_topics(id) ON DELETE SET NULL,
                book_lesson_id       uuid REFERENCES book_lessons(id) ON DELETE SET NULL,
                page_start           int,
                page_end             int,
                target_minutes       int NOT NULL DEFAULT 0,
                question_count       int,
                sequence             int NOT NULL DEFAULT 0,
                is_repeat_of_item_id uuid REFERENCES study_plan_items(id) ON DELETE SET NULL,
                completed_at         timestamptz
            );
            CREATE INDEX IF NOT EXISTS study_plan_items_day ON study_plan_items (study_plan_day_id, sequence);

            CREATE TABLE IF NOT EXISTS tests (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id     uuid REFERENCES organizations(id) ON DELETE CASCADE,
                created_by          uuid REFERENCES users(id) ON DELETE SET NULL,
                study_plan_id       uuid REFERENCES study_plans(id) ON DELETE SET NULL,
                study_plan_day_id   uuid REFERENCES study_plan_days(id) ON DELETE SET NULL,
                exam_id             uuid NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
                blueprint_id        uuid REFERENCES exam_blueprints(id) ON DELETE SET NULL,
                syllabus_version_id uuid REFERENCES syllabus_versions(id) ON DELETE SET NULL,
                type                test_type NOT NULL,
                prediction_model    int,
                title               text NOT NULL,
                language_id         uuid NOT NULL REFERENCES languages(id),
                question_count      int NOT NULL DEFAULT 0,
                total_marks         numeric(8,2),
                duration_minutes    int,
                negative_marking    numeric(4,2) NOT NULL DEFAULT 0,
                difficulty_mix      jsonb,
                -- The exact weights used, so an assembly is reproducible.
                selection_profile   jsonb,
                status              test_status NOT NULL DEFAULT 'draft',
                is_shared           boolean NOT NULL DEFAULT false,
                generated_by_job_id uuid,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS tests_org_type ON tests (organization_id, type, created_at DESC);

            CREATE TABLE IF NOT EXISTS test_questions (
                id                      uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                test_id                 uuid NOT NULL REFERENCES tests(id) ON DELETE CASCADE,
                question_id             uuid NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
                -- Pinned: editing a question later must not change a paper
                -- a teacher has already handed out.
                question_version        int NOT NULL DEFAULT 1,
                question_translation_id uuid REFERENCES question_translations(id) ON DELETE SET NULL,
                sequence                int NOT NULL,
                section                 text,
                marks                   numeric(6,2) NOT NULL DEFAULT 1,
                option_order            char(1)[] NOT NULL DEFAULT '{A,B,C,D}',
                substituted             boolean NOT NULL DEFAULT false,
                CONSTRAINT test_questions_sequence_unique UNIQUE (test_id, sequence),
                CONSTRAINT test_questions_question_unique UNIQUE (test_id, question_id)
            );

            CREATE TABLE IF NOT EXISTS test_attempts (
                id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id uuid NOT NULL REFERENCES organizations(id) ON DELETE CASCADE,
                test_id         uuid NOT NULL REFERENCES tests(id) ON DELETE CASCADE,
                user_id         uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                attempt_number  int NOT NULL DEFAULT 1,
                mode            text NOT NULL DEFAULT 'timed',
                language_id     uuid NOT NULL REFERENCES languages(id),
                started_at      timestamptz NOT NULL DEFAULT now(),
                submitted_at    timestamptz,
                expires_at      timestamptz,
                status          text NOT NULL DEFAULT 'in_progress',
                CONSTRAINT test_attempts_unique UNIQUE (test_id, user_id, attempt_number)
            );

            CREATE TABLE IF NOT EXISTS test_answers (
                id                 uuid NOT NULL DEFAULT uuid_generate_v7(),
                test_attempt_id    uuid NOT NULL,
                test_question_id   uuid NOT NULL,
                question_id        uuid NOT NULL,
                selected_option    char(1),
                is_correct         boolean,
                is_marked_review   boolean NOT NULL DEFAULT false,
                time_spent_seconds int NOT NULL DEFAULT 0,
                answered_at        timestamptz NOT NULL DEFAULT now(),
                PRIMARY KEY (id, answered_at)
            ) PARTITION BY RANGE (answered_at);
            CREATE INDEX IF NOT EXISTS test_answers_attempt ON test_answers (test_attempt_id);
            CREATE INDEX IF NOT EXISTS test_answers_question ON test_answers (question_id, answered_at);

            CREATE TABLE IF NOT EXISTS test_results (
                id                   uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                test_attempt_id      uuid NOT NULL UNIQUE REFERENCES test_attempts(id) ON DELETE CASCADE,
                total_questions      int NOT NULL,
                attempted            int NOT NULL,
                correct              int NOT NULL,
                wrong                int NOT NULL,
                unattempted          int NOT NULL,
                raw_score            numeric(8,2) NOT NULL,
                negative_score       numeric(8,2) NOT NULL DEFAULT 0,
                final_score          numeric(8,2) NOT NULL,
                accuracy             numeric(5,2) NOT NULL,
                percentile           numeric(5,2),
                time_taken_seconds   int,
                topic_breakdown      jsonb NOT NULL DEFAULT '{}'::jsonb,
                difficulty_breakdown jsonb NOT NULL DEFAULT '{}'::jsonb,
                weak_topics          uuid[] NOT NULL DEFAULT '{}',
                strong_topics        uuid[] NOT NULL DEFAULT '{}',
                computed_at          timestamptz NOT NULL DEFAULT now()
            );

            -- Adaptive engine state: an EWMA per (student, topic).
            CREATE TABLE IF NOT EXISTS topic_mastery (
                id                 uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                user_id            uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                exam_id            uuid NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
                canonical_topic_id uuid NOT NULL REFERENCES canonical_topics(id) ON DELETE CASCADE,
                attempts           int NOT NULL DEFAULT 0,
                correct            int NOT NULL DEFAULT 0,
                mastery            numeric(5,4) NOT NULL DEFAULT 0.5,
                last_practiced_at  timestamptz,
                next_review_at     timestamptz,
                CONSTRAINT topic_mastery_unique UNIQUE (user_id, exam_id, canonical_topic_id)
            );

            ALTER TABLE study_plans ENABLE ROW LEVEL SECURITY;
            ALTER TABLE study_plans FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS study_plans_tenant ON study_plans;
            CREATE POLICY study_plans_tenant ON study_plans
            USING (rls_bypass() OR is_platform_admin() OR organization_id = current_org_id())
            WITH CHECK (rls_bypass() OR organization_id = current_org_id());

            ALTER TABLE test_attempts ENABLE ROW LEVEL SECURITY;
            ALTER TABLE test_attempts FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS test_attempts_tenant ON test_attempts;
            CREATE POLICY test_attempts_tenant ON test_attempts
            USING (rls_bypass() OR is_platform_admin() OR organization_id = current_org_id())
            WITH CHECK (rls_bypass() OR organization_id = current_org_id());
        SQL);

        PartitionManager::ensureMonthlyRange('test_answers', -1, 3);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS topic_mastery, test_results, test_answers,
                test_attempts, test_questions CASCADE;
            DROP TABLE IF EXISTS tests CASCADE;
            DROP TABLE IF EXISTS study_plan_items, study_plan_days CASCADE;
            DROP TABLE IF EXISTS study_plans, plan_blueprints CASCADE;
            DROP TYPE IF EXISTS test_status, test_type, plan_status, plan_mode;
        SQL);
    }
};
