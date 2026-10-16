<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * What a paper in the real exam's pattern needs beyond a question count.
 *
 * - blueprint_sections.language_id: a part printed in one language whatever
 *   the paper's language. TNPSC Group IV Part A is the Tamil eligibility
 *   test; an English-medium candidate still sits it in Tamil.
 * - blueprint_sections.qualifying_percent: the share of a part's marks a
 *   candidate must reach for the rest of the paper to count (40% of Part A).
 * - test_questions.fixed_language_id: that language, carried onto each
 *   question of the part, so the runner and every document print it in that
 *   language alone instead of marking it "not available" in the paper's.
 * - test_results.section_breakdown: the score per part, in paper order.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE blueprint_sections
                ADD COLUMN IF NOT EXISTS language_id uuid NULL REFERENCES languages(id),
                ADD COLUMN IF NOT EXISTS qualifying_percent numeric(5,2) NULL;

            ALTER TABLE test_questions
                ADD COLUMN IF NOT EXISTS fixed_language_id uuid NULL REFERENCES languages(id);

            ALTER TABLE test_results
                ADD COLUMN IF NOT EXISTS section_breakdown jsonb NOT NULL DEFAULT '[]'::jsonb;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE test_results DROP COLUMN IF EXISTS section_breakdown;
            ALTER TABLE test_questions DROP COLUMN IF EXISTS fixed_language_id;
            ALTER TABLE blueprint_sections
                DROP COLUMN IF EXISTS qualifying_percent,
                DROP COLUMN IF EXISTS language_id;
        SQL);
    }
};
