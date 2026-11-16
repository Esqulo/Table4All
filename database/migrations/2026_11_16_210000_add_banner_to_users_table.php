<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Wide cover picture shown behind the restaurant's public profile header. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('banner')->nullable()->after('logo');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('banner');
        });
    }
};
