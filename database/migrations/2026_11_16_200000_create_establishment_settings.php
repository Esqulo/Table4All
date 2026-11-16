<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('logo')->nullable()->after('avatar');
        });

        // Weekday follows Carbon's dayOfWeek (0 = Sunday). A closing time at or
        // before the opening time means the shift ends after midnight.
        Schema::create('restaurant_opening_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');
            $table->time('opens_at');
            $table->time('closes_at');
            $table->timestamps();

            $table->index(['user_id', 'weekday']);
        });

        Schema::create('restaurant_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_photos');
        Schema::dropIfExists('restaurant_opening_hours');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('logo');
        });
    }
};
