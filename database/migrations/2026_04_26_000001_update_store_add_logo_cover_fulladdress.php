<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            // Remove os campos antigos
            $table->dropColumn(['logo_path', 'cover_image_path']);
            // Adiciona os novos campos
            $table->string('logo_url')->nullable();
            $table->string('cover_image_url')->nullable();
            $table->string('full_address')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('logo_path')->nullable();
            $table->string('cover_image_path')->nullable();
            $table->dropColumn(['logo_url', 'cover_image_url', 'full_address']);
        });
    }
};
