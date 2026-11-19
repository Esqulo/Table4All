<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One-time QR codes a customer shows to staff opening a table, so the new
     * table is linked to them. Each lives one minute and works once.
     */
    public function up(): void
    {
        Schema::create('customer_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            // The table opened with it, so the customer's screen can follow them there.
            $table->foreignId('restaurant_table_id')->nullable()->constrained('restaurant_tables')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index('restaurant_table_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_link_tokens');
    }
};
