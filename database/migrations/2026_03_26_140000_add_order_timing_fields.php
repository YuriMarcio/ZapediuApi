<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // When the store sets "Aceitar Pedido", we store a deadline so the
            // frontend can show the countdown timer (tempo restante).
            $table->timestamp('estimated_ready_at')->nullable()->after('ordered_at');

            // Rejection reason – shown in history detail.
            $table->string('rejection_reason', 255)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['estimated_ready_at', 'rejection_reason']);
        });
    }
};
