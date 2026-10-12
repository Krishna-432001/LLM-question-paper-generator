<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Cross-exam normalized taxonomy. This is the join point that lets a
            -- new exam inherit the entire existing question bank for free.
            CREATE TABLE canonical_topics (
                id         uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                parent_id  uuid REFERENCES canonical_topics(id) ON DELETE CASCADE,
                slug       text NOT NULL UNIQUE,
                name       text NOT NULL,
                domain     text NOT NULL,
                path       ltree NOT NULL,
                depth      int NOT NULL DEFAULT 1,
                aliases    text[] NOT NULL DEFAULT '{}',
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX canonical_topics_path_gist ON canonical_topics USING gist (path);
            CREATE INDEX canonical_topics_domain_idx ON canonical_topics (domain);

            CREATE TABLE syllabi (
                id         uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                exam_id    uuid NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
                name       text NOT NULL,
                created_at timestamptz NOT NULL DEFAULT now(),
                updated_at timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE syllabus_versions (
                id                uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                syllabus_id       uuid NOT NULL REFERENCES syllabi(id) ON DELETE CASCADE,
                exam_version_id   uuid NOT NULL REFERENCES exam_versions(id),
                version_number    int NOT NULL,
                effective_from    date NOT NULL,
                effective_to      date,
                status            text NOT NULL DEFAULT 'draft',
                source_id         uuid,                     -- FK added with sources
                extraction_job_id uuid,
                published_by      uuid REFERENCES users(id),
                published_at      timestamptz,
                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT syllabus_versions_status_check
                    CHECK (status IN ('draft','active','superseded','archived')),
                CONSTRAINT syllabus_versions_unique UNIQUE (syllabus_id, version_number)
            );
            -- A syllabus version is immutable once active; corrections create v+1.
            CREATE UNIQUE INDEX syllabus_versions_one_active
                ON syllabus_versions (syllabus_id) WHERE status = 'active';

            CREATE TABLE exam_units (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                syllabus_version_id uuid NOT NULL REFERENCES syllabus_versions(id) ON DELETE CASCADE,
                exam_subject_id     uuid NOT NULL REFERENCES exam_subjects(id) ON DELETE CASCADE,
                code                text,
                name                text NOT NULL,
                weight              numeric(5,4) NOT NULL DEFAULT 0,
                sequence            int NOT NULL DEFAULT 0,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX exam_units_version_idx ON exam_units (syllabus_version_id);

            -- Self-referencing tree: real syllabi vary in depth, so unit/topic/
            -- subtopic fixed tables would not fit them.
            CREATE TABLE exam_topics (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                syllabus_version_id uuid NOT NULL REFERENCES syllabus_versions(id) ON DELETE CASCADE,
                exam_unit_id        uuid NOT NULL REFERENCES exam_units(id) ON DELETE CASCADE,
                parent_id           uuid REFERENCES exam_topics(id) ON DELETE CASCADE,
                canonical_topic_id  uuid REFERENCES canonical_topics(id),
                name                text NOT NULL,
                path                ltree NOT NULL,
                depth               int NOT NULL DEFAULT 1,
                is_leaf             boolean NOT NULL DEFAULT true,
                weight              numeric(5,4) NOT NULL DEFAULT 0,
                estimated_minutes   int NOT NULL DEFAULT 45,
                sequence            int NOT NULL DEFAULT 0,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX exam_topics_path_gist ON exam_topics USING gist (path);
            CREATE INDEX exam_topics_canonical_idx ON exam_topics (canonical_topic_id);
            CREATE INDEX exam_topics_version_idx ON exam_topics (syllabus_version_id);

            CREATE TABLE exam_topic_translations (
                exam_topic_id uuid NOT NULL REFERENCES exam_topics(id) ON DELETE CASCADE,
                language_id   uuid NOT NULL REFERENCES languages(id),
                name          text NOT NULL,
                PRIMARY KEY (exam_topic_id, language_id)
            );

            CREATE TABLE syllabus_version_diffs (
                id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                from_version_id uuid REFERENCES syllabus_versions(id) ON DELETE CASCADE,
                to_version_id   uuid NOT NULL REFERENCES syllabus_versions(id) ON DELETE CASCADE,
                change_type     text NOT NULL,
                exam_topic_id   uuid,
                detail          jsonb NOT NULL DEFAULT '{}'::jsonb,
                created_at      timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT syllabus_version_diffs_type_check CHECK (change_type IN
                    ('added','removed','renamed','moved','reweighted'))
            );

            CREATE TABLE exam_blueprints (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                exam_paper_id       uuid NOT NULL REFERENCES exam_papers(id) ON DELETE CASCADE,
                syllabus_version_id uuid NOT NULL REFERENCES syllabus_versions(id),
                name                text NOT NULL,
                total_questions     int NOT NULL,
                duration_minutes    int NOT NULL,
                difficulty_mix      jsonb NOT NULL DEFAULT '{"easy":0.4,"medium":0.4,"hard":0.2}'::jsonb,
                is_default          boolean NOT NULL DEFAULT false,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE blueprint_sections (
                id                 uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                blueprint_id       uuid NOT NULL REFERENCES exam_blueprints(id) ON DELETE CASCADE,
                name               text NOT NULL,
                exam_subject_id    uuid REFERENCES exam_subjects(id),
                question_count     int NOT NULL,
                marks_per_question numeric(6,2) NOT NULL DEFAULT 1,
                topic_weights      jsonb NOT NULL DEFAULT '{}'::jsonb,
                difficulty_mix     jsonb,
                sequence           int NOT NULL DEFAULT 0
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS blueprint_sections;
            DROP TABLE IF EXISTS exam_blueprints CASCADE;
            DROP TABLE IF EXISTS syllabus_version_diffs;
            DROP TABLE IF EXISTS exam_topic_translations;
            DROP TABLE IF EXISTS exam_topics CASCADE;
            DROP TABLE IF EXISTS exam_units CASCADE;
            DROP TABLE IF EXISTS syllabus_versions CASCADE;
            DROP TABLE IF EXISTS syllabi CASCADE;
            DROP TABLE IF EXISTS canonical_topics CASCADE;
        SQL);
    }
};
