<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->decimal('delivery_radius_km', 5, 2)->nullable()->after('longitude');
            $table->decimal('delivery_fee_per_km', 6, 2)->nullable()->after('delivery_radius_km');
            $table->decimal('delivery_fee_min', 6, 2)->nullable()->after('delivery_fee_per_km');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['delivery_radius_km', 'delivery_fee_per_km', 'delivery_fee_min']);
        });
    }
};
