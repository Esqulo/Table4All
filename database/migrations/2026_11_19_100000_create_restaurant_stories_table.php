<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** "Hoje tem!": a restaurant's pictures and videos that disappear 24 hours after posting. */
    public function up(): void
    {
        Schema::create('restaurant_stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('media_type', 10);
            // Public file, set once it is ready (a video is converted after the upload).
            $table->string('path')->nullable();
            // A frame of the video, shown while it loads.
            $table->string('poster_path')->nullable();
            // Uploaded video waiting for conversion, on the private disk.
            $table->string('source_path')->nullable();
            $table->string('status', 12);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            // The feed reads the live ones; the restaurant counts and lists its own.
            $table->index(['user_id', 'expires_at']);
            // The cleanup finds the expired ones.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_stories');
    }
};
