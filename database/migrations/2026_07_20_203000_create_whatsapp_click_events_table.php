<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_click_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_phone')->nullable()->index();
            $table->string('button_payload');
            $table->string('intent')->index();
            $table->boolean('converted')->default(false);
            $table->json('payload')->nullable();
            $table->timestamp('clicked_at')->nullable()->index();
            $table->timestamps();

            $table->index(['company_id', 'converted']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_click_events');
    }
};
