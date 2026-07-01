<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waiter_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waiter_invitations');
    }
};
