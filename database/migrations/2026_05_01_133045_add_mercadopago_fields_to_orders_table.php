<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('mp_payment_id')->nullable()->after('payment_method');
            $table->string('mp_payment_type')->nullable()->after('mp_payment_id');
            $table->string('mp_payment_status')->nullable()->after('mp_payment_type');
            $table->timestamp('mp_payment_approved_at')->nullable()->after('mp_payment_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('mp_payment_id');
            $table->dropColumn('mp_payment_type');
            $table->dropColumn('mp_payment_status');
            $table->dropColumn('mp_payment_approved_at');
        });
    }
};
