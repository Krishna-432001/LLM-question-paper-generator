<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * How many approved questions a topic is supposed to have.
 *
 * Without this, "generate 50" means generate 50 every time it is clicked, and
 * the bank fills with near-duplicates that dedup then throws away — paid for
 * on the way in. With it, the same click asks what is already there and orders
 * only the shortfall.
 *
 * The target is per exam, topic, language and difficulty, because a bank that
 * holds 200 easy questions and no hard ones cannot build a balanced paper, and
 * a single undifferentiated count would hide that completely.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS question_generation_targets (
                id                  uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id     uuid REFERENCES organizations(id) ON DELETE CASCADE,
                exam_id             uuid NOT NULL REFERENCES exams(id) ON DELETE CASCADE,
                canonical_topic_id  uuid REFERENCES canonical_topics(id) ON DELETE CASCADE,
                language_id         uuid NOT NULL REFERENCES languages(id) ON DELETE CASCADE,
                -- NULL means "any difficulty": one row can govern a topic
                -- outright, or three rows can shape its easy/medium/hard mix.
                difficulty          text,
                target_count        integer NOT NULL,
                priority            smallint NOT NULL DEFAULT 5,
                is_active           boolean NOT NULL DEFAULT true,
                created_at          timestamptz NOT NULL DEFAULT now(),
                updated_at          timestamptz NOT NULL DEFAULT now(),

                CONSTRAINT qgt_target_positive CHECK (target_count > 0),
                CONSTRAINT qgt_difficulty_valid CHECK (
                    difficulty IS NULL OR difficulty IN ('easy','medium','hard')
                )
            )
        SQL);

        // One target per cell. Two rows for the same cell would each report a
        // deficit against the same questions and double every order placed.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS qgt_unique_cell
                ON question_generation_targets (
                    exam_id,
                    coalesce(canonical_topic_id, '00000000-0000-0000-0000-000000000000'::uuid),
                    language_id,
                    coalesce(difficulty, 'any')
                )
        SQL);

        DB::statement('ALTER TABLE question_generation_targets ENABLE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS qgt_isolation ON question_generation_targets');
        DB::statement(<<<'SQL'
            CREATE POLICY qgt_isolation ON question_generation_targets
                USING (
                    current_setting('app.bypass_rls', true) = 'on'
                    OR organization_id IS NULL
                    OR organization_id = nullif(current_setting('app.org_id', true), '')::uuid
                )
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS question_generation_targets');
    }
};
