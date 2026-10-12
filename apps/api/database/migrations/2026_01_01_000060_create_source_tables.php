<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$ BEGIN
                CREATE TYPE source_kind AS ENUM (
                    'textbook','reference_book','syllabus','previous_paper',
                    'government_document','notes','current_affairs','other'
                );
            EXCEPTION WHEN duplicate_object THEN NULL;
            END $$;

            DO $$ BEGIN
                CREATE TYPE source_status AS ENUM (
                    'uploaded','scanning','quarantined','extracting','structuring',
                    'chunking','embedding','ready','failed','archived'
                );
            EXCEPTION WHEN duplicate_object THEN NULL;
            END $$;

            CREATE TABLE sources (
                id                uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                organization_id   uuid REFERENCES organizations(id) ON DELETE CASCADE,
                kind              source_kind NOT NULL,
                title             text NOT NULL,
                description       text,
                language_id       uuid NOT NULL REFERENCES languages(id),
                publisher         text,
                edition           text,
                publication_year  int,
                -- Copyright posture is recorded at upload, not assumed.
                rights_basis      text NOT NULL,
                rights_note       text,
                storage_disk      text NOT NULL DEFAULT 'sources',
                storage_path      text NOT NULL,
                original_filename text NOT NULL,
                mime_type         text NOT NULL,
                file_size_bytes   bigint NOT NULL,
                content_sha256    text NOT NULL,
                page_count        int,
                status            source_status NOT NULL DEFAULT 'uploaded',
                -- {stage: {status, at, job_id}} — makes ingestion resumable.
                pipeline_state    jsonb NOT NULL DEFAULT '{}'::jsonb,
                av_scan_result    text,
                ocr_engine        text,
                ocr_confidence    numeric(5,4),
                error_message     text,
                uploaded_by       uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at        timestamptz NOT NULL DEFAULT now(),
                updated_at        timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT sources_rights_basis_check CHECK (rights_basis IN
                    ('public_domain','govt_open','licensed','owned','fair_use'))
            );
            -- Re-uploading the same file in the same tenant is a no-op, not a duplicate.
            CREATE UNIQUE INDEX sources_platform_hash_unique
                ON sources (content_sha256) WHERE organization_id IS NULL;
            CREATE UNIQUE INDEX sources_org_hash_unique
                ON sources (organization_id, content_sha256) WHERE organization_id IS NOT NULL;
            CREATE INDEX sources_status_idx ON sources (status, kind);
            CREATE INDEX sources_org_idx ON sources (organization_id);
            CREATE TRIGGER sources_touch BEFORE UPDATE ON sources
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

            ALTER TABLE exam_versions
                ADD CONSTRAINT exam_versions_source_id_fkey
                FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE SET NULL;
            ALTER TABLE syllabus_versions
                ADD CONSTRAINT syllabus_versions_source_id_fkey
                FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE SET NULL;

            CREATE TABLE books (
                id                 uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                source_id          uuid NOT NULL UNIQUE REFERENCES sources(id) ON DELETE CASCADE,
                organization_id    uuid REFERENCES organizations(id) ON DELETE CASCADE,
                board              text,
                standard           text,
                subject_name       text,
                medium_language_id uuid REFERENCES languages(id),
                volume             text,
                is_indexed         boolean NOT NULL DEFAULT false,
                created_at         timestamptz NOT NULL DEFAULT now(),
                updated_at         timestamptz NOT NULL DEFAULT now()
            );

            CREATE TABLE book_pages (
                id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                book_id         uuid NOT NULL REFERENCES books(id) ON DELETE CASCADE,
                page_number     int NOT NULL,
                raw_text        text,
                normalized_text text,
                layout          jsonb,
                ocr_confidence  numeric(5,4),
                -- A page we do not trust is quarantined rather than chunked:
                -- bad OCR silently poisoning the bank is the worst failure mode.
                needs_review    boolean NOT NULL DEFAULT false,
                reviewed_by     uuid REFERENCES users(id) ON DELETE SET NULL,
                image_path      text,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT book_pages_unique UNIQUE (book_id, page_number)
            );
            CREATE INDEX book_pages_review_idx ON book_pages (book_id) WHERE needs_review;

            CREATE TABLE book_chapters (
                id           uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                book_id      uuid NOT NULL REFERENCES books(id) ON DELETE CASCADE,
                number       text,
                title        text NOT NULL,
                title_native text,
                page_start   int NOT NULL,
                page_end     int NOT NULL,
                sequence     int NOT NULL,
                created_at   timestamptz NOT NULL DEFAULT now(),
                updated_at   timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX book_chapters_book_idx ON book_chapters (book_id, sequence);

            CREATE TABLE book_lessons (
                id              uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                book_chapter_id uuid NOT NULL REFERENCES book_chapters(id) ON DELETE CASCADE,
                number          text,
                title           text NOT NULL,
                title_native    text,
                page_start      int NOT NULL,
                page_end        int NOT NULL,
                sequence        int NOT NULL,
                summary         text,
                question_target int,
                created_at      timestamptz NOT NULL DEFAULT now(),
                updated_at      timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX book_lessons_chapter_idx ON book_lessons (book_chapter_id, sequence);

            CREATE TABLE book_topics (
                id             uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                book_lesson_id uuid NOT NULL REFERENCES book_lessons(id) ON DELETE CASCADE,
                name           text NOT NULL,
                page_start     int,
                page_end       int,
                importance     numeric(5,4) NOT NULL DEFAULT 0.5,
                sequence       int NOT NULL DEFAULT 0
            );

            CREATE TABLE book_topic_mappings (
                id                 uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                book_lesson_id     uuid NOT NULL REFERENCES book_lessons(id) ON DELETE CASCADE,
                canonical_topic_id uuid REFERENCES canonical_topics(id) ON DELETE CASCADE,
                exam_topic_id      uuid REFERENCES exam_topics(id) ON DELETE CASCADE,
                confidence         numeric(5,4) NOT NULL,
                mapped_by          text NOT NULL DEFAULT 'ai',
                verified_by        uuid REFERENCES users(id) ON DELETE SET NULL,
                created_at         timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT book_topic_mappings_unique
                    UNIQUE (book_lesson_id, canonical_topic_id, exam_topic_id)
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS book_topic_mappings;
            DROP TABLE IF EXISTS book_topics;
            DROP TABLE IF EXISTS book_lessons CASCADE;
            DROP TABLE IF EXISTS book_chapters CASCADE;
            DROP TABLE IF EXISTS book_pages;
            DROP TABLE IF EXISTS books CASCADE;
            ALTER TABLE syllabus_versions DROP CONSTRAINT IF EXISTS syllabus_versions_source_id_fkey;
            ALTER TABLE exam_versions DROP CONSTRAINT IF EXISTS exam_versions_source_id_fkey;
            DROP TABLE IF EXISTS sources CASCADE;
            DROP TYPE IF EXISTS source_status;
            DROP TYPE IF EXISTS source_kind;
        SQL);
    }
};
