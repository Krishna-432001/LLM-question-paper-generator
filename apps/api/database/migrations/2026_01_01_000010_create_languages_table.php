<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE languages (
                id          uuid PRIMARY KEY DEFAULT uuid_generate_v7(),
                code        text NOT NULL UNIQUE,          -- ISO 639-1
                name        text NOT NULL,
                native_name text NOT NULL,
                script      text NOT NULL,                 -- Latn, Taml, Telu, Deva...
                direction   char(3) NOT NULL DEFAULT 'ltr',
                font_family text NOT NULL,                 -- used by the PDF renderer
                is_active   boolean NOT NULL DEFAULT false,
                sort_order  int NOT NULL DEFAULT 0,
                created_at  timestamptz NOT NULL DEFAULT now(),
                updated_at  timestamptz NOT NULL DEFAULT now(),
                CONSTRAINT languages_direction_check CHECK (direction IN ('ltr','rtl'))
            );
            CREATE TRIGGER languages_touch BEFORE UPDATE ON languages
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();

            ALTER TABLE users
                ADD CONSTRAINT users_preferred_language_id_fkey
                FOREIGN KEY (preferred_language_id) REFERENCES languages(id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE users DROP CONSTRAINT IF EXISTS users_preferred_language_id_fkey;
            DROP TABLE IF EXISTS languages CASCADE;
        SQL);
    }
};
