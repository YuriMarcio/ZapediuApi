<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Adiciona as coordenadas na tabela das LOJAS
        Schema::table('stores', function (Blueprint $table) {
            $table->decimal('latitude', 10, 8)->nullable()->after('city');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
        });

        // Adiciona as coordenadas na tabela dos CLIENTES
        Schema::table('user_addresses', function (Blueprint $table) {
            $table->decimal('latitude', 10, 8)->nullable()->after('zip_code');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });

        Schema::table('user_addresses', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};