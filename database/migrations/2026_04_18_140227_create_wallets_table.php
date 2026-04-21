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
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            
            // --- Relacionamentos ---
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            // Se você tiver uma tabela 'plans', descomente a linha abaixo:
            // $table->foreignId('plan_id')->nullable()->constrained('plans');

            // --- Controle de Saldo por Tipo de Pagamento ---
            // Separar Pix de Cartão é estratégico para conciliação e taxas diferentes
            $table->decimal('balance_pix', 15, 2)->default(0.00)->comment('Saldo acumulado via PIX');
            $table->decimal('balance_card', 15, 2)->default(0.00)->comment('Saldo acumulado via Cartão');
            $table->decimal('balance_total', 15, 2)->default(0.00)->comment('Soma total disponível');

            // --- Regras de Negócio ---
            $table->boolean('is_enabled_withdrawal')->default(true)->comment('Define se o lojista pode solicitar saque');
            $table->boolean('is_active')->default(true);

            // --- Campos de Integração Mercado Pago (OAuth) ---
            $table->text('mp_access_token')->nullable();
            $table->text('mp_refresh_token')->nullable();
            $table->string('mp_public_key')->nullable();
            $table->string('mp_user_id')->nullable();
            $table->timestamp('mp_expires_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
