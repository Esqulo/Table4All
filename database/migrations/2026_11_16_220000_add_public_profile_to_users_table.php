<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Public profile of the restaurant: its @handle (/vempro/{handle}) and whether it is visible. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('handle', 30)->nullable()->unique()->after('banner');
            $table->boolean('profile_public')->default(true)->after('handle');
        });

        // Existing restaurants get a handle from their name, like new ones do (see User::booted()).
        $used = [];

        DB::table('users')->where('account_type', 'restaurant')->orderBy('id')->get(['id', 'name'])
            ->each(function (object $row) use (&$used): void {
                $base = trim(Str::limit(Str::slug($row->name), 30, ''), '-');
                $base = strlen($base) < 3 ? 'restaurante' : $base;
                $handle = $base;

                for ($n = 2; isset($used[$handle]); $n++) {
                    $handle = rtrim(substr($base, 0, 30 - strlen("-$n")), '-')."-$n";
                }

                $used[$handle] = true;
                DB::table('users')->where('id', $row->id)->update(['handle' => $handle]);
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['handle']);
            $table->dropColumn(['handle', 'profile_public']);
        });
    }
};
