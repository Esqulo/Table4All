<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Shown on the restaurant's public profile: where the venue is and its iFood page. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('address', 255)->nullable()->after('profile_public');
            $table->string('ifood_url', 255)->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['address', 'ifood_url']);
        });
    }
};
