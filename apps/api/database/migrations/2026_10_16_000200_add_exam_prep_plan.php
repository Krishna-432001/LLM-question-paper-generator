<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A plan from the student's own start date to the exam, with every kind of
 * test on it: daily, weekly and monthly tests on the topics just studied,
 * and full mocks in the exam's pattern through the final revision weeks.
 *
 * - plan_mode 'exam_prep': the new mode.
 * - study_plan_items.test_kind: daily | weekly | monthly | mock, so the day
 *   test is built as the right kind of paper.
 * - study_plan_items.scope_days: how many days back its topics come from
 *   (1 for a daily test, 7 for a weekly one); null for the whole syllabus.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TYPE plan_mode ADD VALUE IF NOT EXISTS 'exam_prep';

            ALTER TABLE study_plan_items
                ADD COLUMN IF NOT EXISTS test_kind text NULL,
                ADD COLUMN IF NOT EXISTS scope_days int NULL;
        SQL);
    }

    public function down(): void
    {
        // An enum value cannot be dropped; the columns can.
        DB::unprepared(<<<'SQL'
            ALTER TABLE study_plan_items
                DROP COLUMN IF EXISTS scope_days,
                DROP COLUMN IF EXISTS test_kind;
        SQL);
    }
};
