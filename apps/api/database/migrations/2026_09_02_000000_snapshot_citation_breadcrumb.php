<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A citation must outlive the row it was derived from.
 *
 * question_sources.source_chunk_id is ON DELETE SET NULL, and re-chunking a
 * book deletes and rebuilds every chunk. Rendering the citation through that
 * relation therefore turned every previously approved question into "Unknown
 * source" the moment an admin corrected a heading — silently, on paper that
 * had already been handed out.
 *
 * The breadcrumb is snapshotted at write time instead. It is what was true
 * when the question was created, which is exactly what a citation should say.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE question_sources ADD COLUMN IF NOT EXISTS breadcrumb text');

        // Backfill from the chunks that still exist, so questions written
        // before this migration keep their citation.
        DB::statement(<<<'SQL'
            UPDATE question_sources qs
               SET breadcrumb = c.breadcrumb
              FROM source_chunks c
             WHERE c.id = qs.source_chunk_id
               AND qs.breadcrumb IS NULL
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE question_sources DROP COLUMN IF EXISTS breadcrumb');
    }
};
