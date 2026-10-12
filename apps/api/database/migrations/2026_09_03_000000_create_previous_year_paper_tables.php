<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Previous-year papers: what the exam has actually asked.
 *
 * This is the only evidence the platform has about what matters. A syllabus
 * says a topic exists; ten years of papers say whether it is worth twenty
 * questions or none. SelectionEngine already reserves a `prediction` weight in
 * every profile and has been fed an empty array since it was written — these
 * tables are what fill it.
 *
 * PYQs map to canonical_topics, the same taxonomy generated questions map to.
 * Anything else would leave two parallel vocabularies that could never be
 * joined, and frequency analysis would be unusable for selection.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS previous_year_papers (
                id                uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id   uuid REFERENCES organizations(id) ON DELETE CASCADE,
                exam_id           uuid NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
                source_id         uuid REFERENCES sources(id) ON DELETE SET NULL,
                year              smallint NOT NULL,
                session           text,
                paper_name        text NOT NULL,
                language_id       uuid REFERENCES languages(id) ON DELETE SET NULL,
                total_questions   integer NOT NULL DEFAULT 0,
                parsed_questions  integer NOT NULL DEFAULT 0,
                status            text NOT NULL DEFAULT 'uploaded',
                notes             text,
                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT pyp_year_sane CHECK (year BETWEEN 1950 AND 2100)
            )
        SQL);

        // One paper per exam/year/session. Re-uploading the same paper must
        // update it rather than double every frequency count that follows.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS pyp_unique_paper
                ON previous_year_papers (exam_id, year, coalesce(session, ''), paper_name)
        SQL);

        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS previous_year_questions (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id     uuid REFERENCES organizations(id) ON DELETE CASCADE,
                paper_id            uuid NOT NULL REFERENCES previous_year_papers(id) ON DELETE CASCADE,
                question_number     integer,
                question_text       text NOT NULL,
                option_a            text,
                option_b            text,
                option_c            text,
                option_d            text,
                correct_option      text,
                canonical_topic_id  uuid REFERENCES canonical_topics(id) ON DELETE SET NULL,
                exam_topic_id       uuid REFERENCES exam_topics(id) ON DELETE SET NULL,
                question_type       text NOT NULL DEFAULT 'mcq4',
                difficulty          text,
                marks               numeric(5,2) NOT NULL DEFAULT 1,
                -- Normalised-stem hash. A question repeated across years is the
                -- strongest signal the analyser has, and it is also what stops
                -- a generated question from duplicating a real one.
                question_hash       char(64) NOT NULL,
                mapping_confidence  numeric(4,3),
                mapping_source      text NOT NULL DEFAULT 'unmapped',
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT pyq_option_valid CHECK (
                    correct_option IS NULL OR correct_option IN ('A','B','C','D')
                )
            )
        SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS pyq_unique_in_paper ON previous_year_questions (paper_id, question_number)');
        DB::statement('CREATE INDEX IF NOT EXISTS pyq_hash_idx ON previous_year_questions (question_hash)');
        DB::statement('CREATE INDEX IF NOT EXISTS pyq_topic_idx ON previous_year_questions (canonical_topic_id)');

        /*
         * Derived, not authoritative: this table is a cache of the analysis so
         * selection does not re-aggregate ten years of papers on every request.
         * It is rebuilt wholesale, never edited.
         */
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS topic_frequencies (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                exam_id             uuid NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
                canonical_topic_id  uuid NOT NULL REFERENCES canonical_topics(id) ON DELETE CASCADE,
                total_asked         integer NOT NULL DEFAULT 0,
                years_asked         integer NOT NULL DEFAULT 0,
                years_covered       integer NOT NULL DEFAULT 0,
                first_asked_year    smallint,
                last_asked_year     smallint,
                -- 0..1. Combines how often a topic appears with how recently,
                -- and is what SelectionEngine reads as its prediction weight.
                frequency_score     numeric(5,4) NOT NULL DEFAULT 0,
                trend               text NOT NULL DEFAULT 'stable',
                difficulty_mix      jsonb NOT NULL DEFAULT '{}'::jsonb,
                type_mix            jsonb NOT NULL DEFAULT '{}'::jsonb,
                computed_at         timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT tf_trend_valid CHECK (trend IN ('rising','stable','falling','dormant'))
            )
        SQL);

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS tf_unique ON topic_frequencies (exam_id, canonical_topic_id)');

        // Same three-layer isolation as every other tenant-scoped table:
        // platform rows (organization_id IS NULL) are readable by everyone.
        foreach (['previous_year_papers', 'previous_year_questions'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS {$table}_isolation ON {$table}");
            DB::statement(<<<SQL
                CREATE POLICY {$table}_isolation ON {$table}
                    USING (
                        current_setting('app.bypass_rls', true) = 'on'
                        OR organization_id IS NULL
                        OR organization_id = nullif(current_setting('app.org_id', true), '')::uuid
                    )
            SQL);
        }
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS topic_frequencies');
        DB::statement('DROP TABLE IF EXISTS previous_year_questions');
        DB::statement('DROP TABLE IF EXISTS previous_year_papers');
    }
};
