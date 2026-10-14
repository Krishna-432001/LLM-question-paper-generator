<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A question paper uploaded without its answer key must still be sittable.
 *
 * Two rules stood in the way, and both are kept for every other question:
 *
 *   1. The single-correct-option trigger demanded exactly one correct option.
 *      For a printed past paper the honest answer is "not known yet", so a
 *      previous_paper question may now have none — never more than one. A
 *      generated or authored question still needs exactly one; the bank's
 *      guarantee there is unchanged.
 *   2. Scoring counted every question with no matching option as wrong. A
 *      result now records how many questions had no key at all, so they can
 *      be left out of the score instead of quietly marking the student down.
 *      When nothing answered could be marked, accuracy is NULL — unknown —
 *      rather than a 0% that says every answer was wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assert_single_correct_option() RETURNS trigger AS $$
            DECLARE
                qid      uuid := COALESCE(NEW.question_id, OLD.question_id);
                q_origin question_origin;
                n        int;
            BEGIN
                SELECT origin INTO q_origin FROM questions WHERE id = qid;
                IF NOT FOUND THEN
                    RETURN NULL;   -- question deleted; options cascaded
                END IF;
                SELECT count(*) INTO n FROM question_options
                    WHERE question_id = qid AND is_correct;
                -- A printed paper with no key yet is unkeyed, not wrong.
                IF n > 1 OR (n = 0 AND q_origin <> 'previous_paper') THEN
                    RAISE EXCEPTION 'question % must have exactly one correct option (found %)', qid, n;
                END IF;
                RETURN NULL;
            END $$ LANGUAGE plpgsql;

            ALTER TABLE test_results ADD COLUMN IF NOT EXISTS unscored int NOT NULL DEFAULT 0;
            ALTER TABLE test_results ALTER COLUMN accuracy DROP NOT NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE test_results DROP COLUMN IF EXISTS unscored;
            UPDATE test_results SET accuracy = 0 WHERE accuracy IS NULL;
            ALTER TABLE test_results ALTER COLUMN accuracy SET NOT NULL;

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
        SQL);
    }
};
