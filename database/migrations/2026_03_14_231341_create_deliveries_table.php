<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('external_id')->nullable()->index();
            $table->string('order_code')->nullable()->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable()->index();
            $table->string('address')->nullable();
            $table->string('status')->default('new')->index();
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->string('source')->default('zapi');
            $table->timestamp('last_update_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
