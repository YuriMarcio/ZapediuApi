<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->index();
            $table->string('external_id')->nullable()->unique();
            $table->string('external_reference')->nullable()->index();
            $table->string('payment_status')->default('pending')->index();
            $table->string('payment_type')->nullable()->index();
            $table->string('payment_method')->nullable();
            $table->decimal('gross_amount', 10, 2)->default(0);
            $table->decimal('net_received_amount', 10, 2)->nullable();
            $table->decimal('platform_fee_amount', 10, 2)->default(0);
            $table->decimal('seller_amount', 10, 2)->default(0);
            $table->decimal('products_amount', 10, 2)->default(0);
            $table->decimal('delivery_fee_amount', 10, 2)->default(0);
            $table->string('delivery_mode', 20)->default('store');
            $table->string('plan_slug')->nullable()->index();
            $table->string('payout_mode', 20)->nullable();
            $table->text('checkout_url')->nullable();
            $table->timestamp('seller_release_at')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('last_webhook_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};