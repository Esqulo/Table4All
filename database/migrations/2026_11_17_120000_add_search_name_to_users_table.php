<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Lowercase name without accents, so the restaurant search finds "Café" when
     * someone types "cafe" (the usual way on a phone keyboard). Kept in sync by
     * User::booted(); portable, unlike PostgreSQL's unaccent extension.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('search_name')->nullable()->after('name');
        });

        DB::table('users')->orderBy('id')->select(['id', 'name'])->chunkById(500, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('users')->where('id', $row->id)->update(['search_name' => Str::lower(Str::ascii($row->name))]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('search_name');
        });
    }
};
