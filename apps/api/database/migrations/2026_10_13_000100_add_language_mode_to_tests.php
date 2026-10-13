<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Which languages a paper is printed in.
 *
 * A Tamil Nadu candidate sits the real exam with every question in both Tamil
 * and English, and practises best on a paper that looks the same. So a test is
 * either single-language (the old behaviour, in tests.language_id) or
 * bilingual: tests.language_id printed first, secondary_language_id beneath it.
 *
 * The second CHECK keeps the pair honest at the database: a bilingual paper
 * without a second language, or with the same language twice, would render as
 * a single-language paper while claiming otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE tests
                ADD COLUMN IF NOT EXISTS language_mode text NOT NULL DEFAULT 'single',
                ADD COLUMN IF NOT EXISTS secondary_language_id uuid NULL REFERENCES languages(id);

            DO $$ BEGIN
                ALTER TABLE tests ADD CONSTRAINT tests_language_mode_check
                    CHECK (language_mode IN ('single', 'bilingual'));
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;

            DO $$ BEGIN
                ALTER TABLE tests ADD CONSTRAINT tests_secondary_language_check
                    CHECK (
                        (language_mode = 'single' AND secondary_language_id IS NULL)
                        OR (language_mode = 'bilingual'
                            AND secondary_language_id IS NOT NULL
                            AND secondary_language_id <> language_id)
                    );
            EXCEPTION WHEN duplicate_object THEN NULL; END $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE tests DROP CONSTRAINT IF EXISTS tests_secondary_language_check;
            ALTER TABLE tests DROP CONSTRAINT IF EXISTS tests_language_mode_check;
            ALTER TABLE tests
                DROP COLUMN IF EXISTS secondary_language_id,
                DROP COLUMN IF EXISTS language_mode;
        SQL);
    }
};
