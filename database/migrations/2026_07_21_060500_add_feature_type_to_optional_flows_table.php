<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optional_flows', function (Blueprint $table): void {
            $table->enum('feature_type', ['borda', 'ingrediente', 'molho'])->nullable()->after('store_id');
            $table->unique(['store_id', 'feature_type'], 'optional_flows_store_feature_unique');
        });
    }

    public function down(): void
    {
        Schema::table('optional_flows', function (Blueprint $table): void {
            $table->dropUnique('optional_flows_store_feature_unique');
            $table->dropColumn('feature_type');
        });
    }
};
