<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Banner 1200x530 com o logo centralizado (object-fit: contain) sobre fundo
            // sólido/gradiente — gerado a partir do logo_url pra não depender de crop
            // automático do WhatsApp no card do carrossel. null = usa logo_url cru (fallback).
            $table->string('carousel_banner_url')->nullable()->after('cover_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('carousel_banner_url');
        });
    }
};
