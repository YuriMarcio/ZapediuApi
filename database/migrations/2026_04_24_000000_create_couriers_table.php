<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couriers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->onDelete('cascade');
            // Dados pessoais
            $table->string('first_name');
            $table->string('last_name');
            $table->string('full_name');
            $table->string('cpf', 14)->unique();
            $table->string('email')->nullable();
            $table->string('phone')->unique();
            $table->date('birth_date')->nullable();
            // Veículo
            $table->string('vehicle_type')->nullable();
            $table->string('motorcycle_model')->nullable();
            $table->string('license_plate')->nullable();
            $table->string('cnh_number')->nullable();
            // Cidade/Estado de atuação
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            // Dados de recebimento (PIX)
            $table->enum('pix_key_type', ['CPF', 'TELEFONE', 'EMAIL', 'ALEATORIA'])->nullable();
            $table->string('pix_key')->nullable();
            // Status
            $table->enum('status', ['disponivel', 'em_rota', 'inativo']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couriers');
    }
};
