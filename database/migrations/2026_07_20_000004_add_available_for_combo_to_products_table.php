<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Opt-out de "meio a meio" por sabor: quando false, este produto nunca entra em
            // combinação — nem inicia a pergunta "quer outro sabor?", nem pode ser escolhido
            // como parceiro por outro pedido. Default true preserva o comportamento atual.
            $table->boolean('available_for_combo')->default(true)->after('has_variations');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('available_for_combo');
        });
    }
};
