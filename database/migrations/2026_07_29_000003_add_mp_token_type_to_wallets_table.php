<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            // Já é $fillable em Wallet::$fillable mas nunca foi criada na tabela — grava o
            // "token_type" (ex.: "bearer") devolvido pelo POST oauth/token do Mercado
            // Pago. Sem isso, MercadoPagoController::handleCallback quebra com "Unknown
            // column 'mp_token_type'" ao gravar a wallet.
            $table->string('mp_token_type')->nullable()->after('mp_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn('mp_token_type');
        });
    }
};
