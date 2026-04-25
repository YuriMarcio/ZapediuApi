<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Adicionamos a coluna permitindo que fique vazia (nullable)
            $table->string('group_message_id')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
   public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Caso precise reverter a migration
            $table->dropColumn('group_message_id');
        });
    }
};
