<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The restaurant's loyalty ladder: at `visits` visits in a streak the customer
        // earns a coupon with this discount. Restaurants without rows use the
        // default ladder (config incentives.loyalty.default_tiers).
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('visits');
            $table->string('discount_type', 10);
            $table->decimal('discount_value', 10, 2);
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['restaurant_id', 'visits']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};
