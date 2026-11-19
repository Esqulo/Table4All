<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers at a table. The first customer to open a table becomes its
     * responsible and approves who else joins. A table stays in each customer's
     * account until the restaurant closes it; joining one never blocks joining others.
     */
    public function up(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->foreignId('responsible_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->index('responsible_user_id');
        });

        Schema::create('restaurant_table_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 16)->default('pending');
            // Times the responsible said no; after MAX_REJECTIONS the customer can't ask again.
            $table->unsignedSmallInteger('rejections')->default(0);
            $table->timestamps();

            // Leads with the table id, so it also serves lookups by table.
            $table->unique(['restaurant_table_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_table_customers');

        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsible_user_id');
        });
    }
};
