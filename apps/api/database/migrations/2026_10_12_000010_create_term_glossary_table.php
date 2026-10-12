<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Board-approved terminology for translation.
 *
 * State-board exams expect the textbook's exact Tamil term — ஒளிச்சேர்க்கை
 * for photosynthesis, not a fluent paraphrase — so the translator is given
 * these terms and validation checks they were used.
 *
 * kind = 'term' is enforced vocabulary; kind = 'name' is a proper noun with
 * a fixed transliteration, substituted directly rather than translated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS term_glossary (
                id                 uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                source_language_id uuid NOT NULL REFERENCES languages(id),
                target_language_id uuid NOT NULL REFERENCES languages(id),
                kind               text NOT NULL DEFAULT 'term',
                domain             text,
                source_term        text NOT NULL,
                target_term        text NOT NULL,
                notes              text,
                is_active          boolean NOT NULL DEFAULT true,
                created_by         uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at         timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT term_glossary_kind_check CHECK (kind IN ('term', 'name'))
            );

            CREATE UNIQUE INDEX IF NOT EXISTS term_glossary_unique
                ON term_glossary (source_language_id, target_language_id, lower(source_term), coalesce(domain, ''));
            CREATE INDEX IF NOT EXISTS term_glossary_pair
                ON term_glossary (source_language_id, target_language_id) WHERE is_active;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS term_glossary');
    }
};
