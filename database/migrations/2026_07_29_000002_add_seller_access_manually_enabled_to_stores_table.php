<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Libera gerente/vendedor a editar a loja mesmo fora da janela padrão de 15
            // dias (createstore.md, "Acesso temporário"). Setado manualmente pelo master.
            // Não reinicia o prazo original, é um bypass independente.
            $table->boolean('seller_access_manually_enabled')->default(false)->after('is_test_store');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('seller_access_manually_enabled');
        });
    }
};
