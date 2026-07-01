<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('table_payments', function (Blueprint $table) {
            $table->foreignId('registered_by_id')
                ->nullable()
                ->after('amount')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('registered_by_type', 20)->nullable()->after('registered_by_id');
        });
    }

    public function down(): void
    {
        Schema::table('table_payments', function (Blueprint $table) {
            $table->dropForeign(['registered_by_id']);
            $table->dropColumn(['registered_by_id', 'registered_by_type']);
        });
    }
};
