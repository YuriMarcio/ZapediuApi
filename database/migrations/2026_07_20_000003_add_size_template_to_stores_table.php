<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Lista ordenada de tamanhos ([{key,label,sublabel}, ...]) que dirige a grade fixa
            // de preços do formulário "Novo sabor" (pizzaria/açaiteria). Nulo = usa o default
            // de Store::sizeTemplate() pro business_type da loja.
            $table->json('size_template')->nullable()->after('max_flavors');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('size_template');
        });
    }
};
