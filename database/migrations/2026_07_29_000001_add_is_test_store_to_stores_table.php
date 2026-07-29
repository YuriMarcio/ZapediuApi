<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Loja mockada pra demonstração/teste: aparece no WhatsApp mesmo sem Mercado
            // Pago conectado (createstore.md, "Loja de teste"). Só o master deve poder
            // alternar isso.
            $table->boolean('is_test_store')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('is_test_store');
        });
    }
};
