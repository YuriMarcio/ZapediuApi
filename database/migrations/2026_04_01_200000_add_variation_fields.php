<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add additional_price to product_variations (price already stores base override;
        // additional_price stores the surcharge shown to the customer)
        Schema::table('product_variations', function (Blueprint $table) {
            $table->decimal('additional_price', 10, 2)->default(0)->after('price');
        });

        // Add variation_question and has_variations to products
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('has_variations')->default(false)->after('is_active');
            $table->string('variation_question')->nullable()->after('has_variations');
        });
    }

    public function down(): void
    {
        Schema::table('product_variations', function (Blueprint $table) {
            $table->dropColumn('additional_price');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['has_variations', 'variation_question']);
        });
    }
};
