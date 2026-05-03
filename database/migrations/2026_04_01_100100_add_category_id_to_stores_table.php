<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('segment');
            // Não adicionamos constraint para evitar dependência circular
            // A constraint será adicionada posteriormente via migration separada
            // após a criação de ambas as tabelas
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('category_id');
        });
    }
};
