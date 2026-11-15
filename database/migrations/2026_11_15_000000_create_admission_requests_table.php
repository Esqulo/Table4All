<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requests from bars/restaurants that want a Table4All account,
     * sent from the public contact page and reviewed by admins.
     */
    public function up(): void
    {
        Schema::create('admission_requests', function (Blueprint $table) {
            $table->id();
            $table->string('business_name', 120);
            $table->string('contact_email');
            $table->string('phone', 20)->nullable();
            $table->string('city', 120)->nullable();
            $table->unsignedSmallInteger('tables')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_requests');
    }
};
