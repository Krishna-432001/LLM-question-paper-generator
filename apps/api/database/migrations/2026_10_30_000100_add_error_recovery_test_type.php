<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An error-recovery test: only questions the student got wrong, brought back
 * 1, 3 and 7 days later until they have been answered right three times in a
 * row. The answers already live in test_answers; this adds only the type.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TYPE test_type ADD VALUE IF NOT EXISTS 'error_recovery';

            CREATE INDEX IF NOT EXISTS test_answers_question_answered_idx
                ON test_answers (question_id, answered_at);
        SQL);
    }

    public function down(): void
    {
        // An enum value cannot be dropped; the index can.
        DB::unprepared('DROP INDEX IF EXISTS test_answers_question_answered_idx;');
    }
};
