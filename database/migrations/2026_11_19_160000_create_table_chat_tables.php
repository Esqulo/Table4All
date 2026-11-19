<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chat among the customers at a table: short messages, and emoji reactions
     * that float up on everyone's screen. Reactions are fleeting and get pruned.
     */
    public function up(): void
    {
        Schema::create('table_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('body', 140);
            $table->timestamp('created_at')->useCurrent();

            // The latest messages of a table, newest first.
            $table->index(['restaurant_table_id', 'id']);
            $table->index('user_id');
        });

        Schema::create('table_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['restaurant_table_id', 'created_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_reactions');
        Schema::dropIfExists('table_messages');
    }
};
