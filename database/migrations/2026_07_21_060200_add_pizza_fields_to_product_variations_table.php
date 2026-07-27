<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variations', function (Blueprint $table): void {
            $table->foreignId('store_pizza_size_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            $table->enum('price_mode', ['inherit', 'override'])->default('override')->after('additional_price');
            $table->decimal('override_price', 10, 2)->nullable()->after('price_mode');
        });
    }

    public function down(): void
    {
        Schema::table('product_variations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('store_pizza_size_id');
            $table->dropColumn(['price_mode', 'override_price']);
        });
    }
};
