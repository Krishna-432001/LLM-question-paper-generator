<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The exam a student is preparing for. Every student screen is scoped to one
 * exam, and keeping the choice on the account (rather than in the browser)
 * means it follows the student from phone to laptop.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN IF NOT EXISTS target_exam_id uuid REFERENCES exams(id) ON DELETE SET NULL;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE users DROP COLUMN IF EXISTS target_exam_id');
    }
};
