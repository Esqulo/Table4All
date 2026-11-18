<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Full-text search over the restaurant's name and @handle (RestaurantDirectory::search).
     *
     * Built from search_name (already lowercase and without accents, see User::booted()) and
     * the handle with its separators turned into spaces, so "bar-do-ze" is the words "bar",
     * "do" and "ze". The 'simple' configuration keeps every word as typed: no stemming and no
     * stop words, since names are not prose ("do" in "Bar do Zé" must still match).
     * PostgreSQL keeps the column up to date on every write; the GIN index serves the search.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE users ADD COLUMN search_vector tsvector
            GENERATED ALWAYS AS (
                to_tsvector('simple', coalesce(search_name, '') || ' ' || translate(coalesce(handle, ''), '-_', '  '))
            ) STORED
        SQL);
        DB::statement('CREATE INDEX users_search_vector_index ON users USING GIN (search_vector)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS users_search_vector_index');
        DB::statement('ALTER TABLE users DROP COLUMN IF EXISTS search_vector');
    }
};
