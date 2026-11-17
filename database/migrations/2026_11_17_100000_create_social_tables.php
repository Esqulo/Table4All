<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The social side of the public profile: posts, emoji reactions, comments and reviews. */
    public function up(): void
    {
        // user_id is the restaurant owner. A post has a text, a picture, or both.
        Schema::create('restaurant_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->string('image')->nullable();
            // Aspect the picture was cropped to, so the feed reserves its space before it loads.
            $table->string('image_aspect', 16)->nullable();
            $table->timestamps();

            // Profile feed: newest first, keyset by id.
            $table->index(['user_id', 'id']);
        });

        // One reaction per person per post; changing the emoji replaces it.
        Schema::create('post_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('restaurant_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 64);
            $table->timestamps();

            $table->unique(['post_id', 'user_id']);
            $table->index(['post_id', 'emoji']);
            $table->index('user_id');
        });

        Schema::create('post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('restaurant_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['post_id', 'id']);
            $table->index('user_id');
        });

        // Customer reviews of a restaurant (1 to 5 stars). Shown on the profile; submitting comes later.
        Schema::create('restaurant_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_reviews');
        Schema::dropIfExists('post_comments');
        Schema::dropIfExists('post_reactions');
        Schema::dropIfExists('restaurant_posts');
    }
};
