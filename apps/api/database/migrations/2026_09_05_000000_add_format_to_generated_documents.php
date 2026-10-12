<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A document has a format as well as a type.
 *
 * The table was written when every document was a PDF, so "question paper"
 * and "PDF" were the same fact. They are not: the same paper has to leave the
 * platform as a PDF for printing, a DOCX for a teacher to edit, an XLSX for
 * bulk review, and JSON for the student site to import.
 *
 * Adding the dimension here rather than in a parallel `exports` table keeps
 * one storage path, one content-hash reuse rule and one download route. A
 * second table would have needed its own copy of all three.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE document_format AS ENUM ('pdf', 'docx', 'xlsx', 'json');
            EXCEPTION WHEN duplicate_object THEN NULL; END $$
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE generated_documents
                ADD COLUMN IF NOT EXISTS format document_format NOT NULL DEFAULT 'pdf'
        SQL);

        // Every existing row predates the column and is a PDF, which the
        // default already states correctly.
        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS generated_documents_format_idx
                ON generated_documents (documentable_type, documentable_id, type, format)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS generated_documents_format_idx');
        DB::statement('ALTER TABLE generated_documents DROP COLUMN IF EXISTS format');
        DB::statement('DROP TYPE IF EXISTS document_format');
    }
};
