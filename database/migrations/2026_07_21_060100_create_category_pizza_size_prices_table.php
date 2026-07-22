<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_pizza_size_prices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_pizza_size_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->timestamps();
            $table->unique(['category_id', 'store_pizza_size_id'], 'category_size_price_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_pizza_size_prices');
    }
};
