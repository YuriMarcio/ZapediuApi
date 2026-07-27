<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Define qual fluxo de cardápio a loja usa: pizzaria e açaiteria compartilham o
            // mesmo fluxo de tamanhos + "meio a meio" (ver App\Models\Store::BUSINESS_TYPES).
            $table->string('business_type')->default('outros')->after('segment');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('business_type');
        });
    }
};
