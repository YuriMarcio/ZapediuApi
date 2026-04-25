<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Remove foreign key and column delivery_id
            if (Schema::hasColumn('orders', 'delivery_id')) {
                $table->dropForeign(['delivery_id']);
                $table->dropColumn('delivery_id');
            }
            // Add courier_id
            $table->unsignedBigInteger('courier_id')->nullable()->after('company_id');
            $table->foreign('courier_id')->references('id')->on('couriers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Remove courier_id foreign key and column
            $table->dropForeign(['courier_id']);
            $table->dropColumn('courier_id');
            // Restore delivery_id (no FK for down, as original details are unknown)
            $table->unsignedBigInteger('delivery_id')->nullable();
        });
    }
};
