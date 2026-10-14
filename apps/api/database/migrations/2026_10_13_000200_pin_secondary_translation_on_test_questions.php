<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The second language's translation, pinned per question like the first.
 *
 * test_questions.question_translation_id already records which row a paper's
 * primary language was assembled from. A bilingual paper needs the same for
 * its second language: re-translation adds new version rows and marks the old
 * ones STALE, so looking the text up again at print time can pick a different
 * row from the one the student saw in the runner — or none at all.
 *
 * ON DELETE SET NULL, as for the primary pin: a deleted translation leaves the
 * paper readable through a live lookup rather than taking the paper with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE test_questions
                ADD COLUMN IF NOT EXISTS secondary_question_translation_id uuid NULL
                    REFERENCES question_translations(id) ON DELETE SET NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE test_questions DROP COLUMN IF EXISTS secondary_question_translation_id');
    }
};
