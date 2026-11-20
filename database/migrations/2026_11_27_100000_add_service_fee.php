<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The service fee ("taxa de serviço", App\Support\ServiceFee). The restaurant sets it in
     * its venue settings: a fixed amount per table, or a percentage of the products with an
     * optional cap. Each table copies the terms when it opens (like the order prices), so
     * changing the setting never changes a bill already running; staff can waive it per table.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('service_fee_type', 10)->nullable()->after('table_assignment_enabled');
            $table->decimal('service_fee_value', 10, 2)->nullable()->after('service_fee_type');
            $table->decimal('service_fee_cap', 10, 2)->nullable()->after('service_fee_value');
        });

        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->string('service_fee_type', 10)->nullable()->after('waiter_id');
            $table->decimal('service_fee_value', 10, 2)->nullable()->after('service_fee_type');
            $table->decimal('service_fee_cap', 10, 2)->nullable()->after('service_fee_value');
            $table->boolean('service_fee_waived')->default(false)->after('service_fee_cap');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table) {
            $table->dropColumn(['service_fee_type', 'service_fee_value', 'service_fee_cap', 'service_fee_waived']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['service_fee_type', 'service_fee_value', 'service_fee_cap']);
        });
    }
};
