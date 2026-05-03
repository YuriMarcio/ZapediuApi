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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('segment')->default('food')->index();
            $table->string('api_token', 120)->unique();
            $table->string('zapi_instance_id')->nullable()->index();
            $table->string('zapi_instance_token')->nullable();
            $table->string('zapi_client_token')->nullable();
            $table->string('zapi_webhook_token')->nullable();
            $table->json('shipping_rules')->nullable();
            $table->json('business_hours')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
