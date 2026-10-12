<?php

declare(strict_types=1);

use App\Support\Database\VectorSupport;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2 — the retrieval substrate.
 *
 * Chunks are the unit of citation: every generated question points at a
 * source_chunk, which points at a page range in a book. That chain is what
 * makes a question verifiable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE chunk_type AS ENUM (
                    'prose','definition','table','list','caption',
                    'exercise','callout','summary','formula'
                );
            EXCEPTION WHEN duplicate_object THEN NULL;
            END $$;

            CREATE TABLE IF NOT EXISTS source_chunks (
                id             uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                source_id      uuid NOT NULL REFERENCES sources(id) ON DELETE CASCADE,
                book_id        uuid REFERENCES books(id) ON DELETE CASCADE,
                book_lesson_id uuid REFERENCES book_lessons(id) ON DELETE SET NULL,
                chunk_index    int NOT NULL,
                chunk_type     chunk_type NOT NULL DEFAULT 'prose',
                -- Breadcrumb is stored INSIDE the chunk text at embed time as
                -- well; keeping it as a column too makes citations cheap.
                breadcrumb     text NOT NULL,
                text           text NOT NULL,
                token_count    int NOT NULL,
                page_start     int,
                page_end       int,
                language_id    uuid NOT NULL REFERENCES languages(id),
                -- Computed at index time from typography and structure signals,
                -- never by a model: biases retrieval toward what the book
                -- itself marks as examinable.
                importance     numeric(5,4) NOT NULL DEFAULT 0.5,
                is_exercise    boolean NOT NULL DEFAULT false,
                content_hash   text NOT NULL,
                fts            tsvector GENERATED ALWAYS AS (to_tsvector('simple', text)) STORED,
                created_at     timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_chunks_unique UNIQUE (source_id, chunk_index)
            );

            CREATE INDEX IF NOT EXISTS source_chunks_fts    ON source_chunks USING gin (fts);
            CREATE INDEX IF NOT EXISTS source_chunks_trgm   ON source_chunks USING gin (text gin_trgm_ops);
            CREATE INDEX IF NOT EXISTS source_chunks_lesson ON source_chunks (book_lesson_id);
            CREATE INDEX IF NOT EXISTS source_chunks_hash   ON source_chunks (content_hash);
            CREATE INDEX IF NOT EXISTS source_chunks_source ON source_chunks (source_id, chunk_index);
        SQL);

        // Embedding column type depends on whether pgvector is installed.
        $vectorType = VectorSupport::columnType();

        DB::unprepared(<<<SQL
            CREATE TABLE IF NOT EXISTS source_embeddings (
                id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                source_chunk_id uuid NOT NULL REFERENCES source_chunks(id) ON DELETE CASCADE,
                embedding_model text NOT NULL DEFAULT 'multilingual-e5-large',
                embedding       {$vectorType} NOT NULL,
                created_at      timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT source_embeddings_unique UNIQUE (source_chunk_id, embedding_model)
            );
        SQL);

        if (VectorSupport::isAvailable()) {
            DB::unprepared(<<<'SQL'
                CREATE INDEX IF NOT EXISTS source_embeddings_hnsw
                    ON source_embeddings USING hnsw (embedding vector_cosine_ops)
                    WITH (m = 16, ef_construction = 64);
            SQL);
        }

        DB::unprepared(<<<'SQL'
            -- Demand signal: a topic a student needed but the bank could not
            -- serve becomes a ranked content backlog item.
            CREATE TABLE IF NOT EXISTS coverage_gaps (
                id                 uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                exam_id            uuid REFERENCES exams(id) ON DELETE CASCADE,
                canonical_topic_id uuid REFERENCES canonical_topics(id) ON DELETE CASCADE,
                language_id        uuid REFERENCES languages(id),
                difficulty         text,
                deficit            int NOT NULL DEFAULT 0,
                demand_count       int NOT NULL DEFAULT 1,
                detail             jsonb NOT NULL DEFAULT '{}'::jsonb,
                last_seen_at       timestamptz NOT NULL DEFAULT now(),
                resolved_at        timestamptz,
                created_at         timestamptz NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX IF NOT EXISTS coverage_gaps_unique
                ON coverage_gaps (exam_id, canonical_topic_id, language_id, difficulty);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS coverage_gaps;
            DROP TABLE IF EXISTS source_embeddings;
            DROP TABLE IF EXISTS source_chunks;
            DROP TYPE IF EXISTS chunk_type;
        SQL);
    }
};
