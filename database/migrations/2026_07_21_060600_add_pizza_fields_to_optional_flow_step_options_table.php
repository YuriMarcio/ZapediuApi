<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optional_flow_step_options', function (Blueprint $table): void {
            $table->enum('pricing_mode', ['single', 'per_size'])->default('single')->after('merchant_price');
            $table->json('available_size_ids')->nullable()->after('pricing_mode');
            $table->unsignedTinyInteger('max_quantity')->nullable()->after('available_size_ids');
        });
    }

    public function down(): void
    {
        Schema::table('optional_flow_step_options', function (Blueprint $table): void {
            $table->dropColumn(['pricing_mode', 'available_size_ids', 'max_quantity']);
        });
    }
};
