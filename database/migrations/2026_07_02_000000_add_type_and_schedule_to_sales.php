<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('type')->default('periodic')->after('user_id');
            $table->dateTime('starts_at')->nullable()->after('end_time');
            $table->dateTime('ends_at')->nullable()->after('starts_at');
        });

        // Make periodic-only columns nullable so scheduled sales can omit them.
        // SQLite does not support ALTER COLUMN — the columns were created nullable
        // in the original migration for SQLite, so we only need this for PostgreSQL.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales ALTER COLUMN days DROP NOT NULL');
            DB::statement('ALTER TABLE sales ALTER COLUMN start_time DROP NOT NULL');
            DB::statement('ALTER TABLE sales ALTER COLUMN end_time DROP NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['type', 'starts_at', 'ends_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales ALTER COLUMN days SET NOT NULL');
            DB::statement('ALTER TABLE sales ALTER COLUMN start_time SET NOT NULL');
            DB::statement('ALTER TABLE sales ALTER COLUMN end_time SET NOT NULL');
        }
    }
};
