<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();           // start | turbo
            $table->string('name');                     // Start | Turbo
            $table->string('tagline');                  // Subtítulo exibido no card
            $table->text('pitch');                      // Descrição/objetivo do plano
            $table->decimal('fee_percent', 5, 2);       // Taxa percentual (ex: 7.90)
            $table->decimal('fee_fixed', 8, 2)->default(1.00); // Taxa fixa por pedido (ex: 1.00)
            $table->json('features');                   // Lista de vantagens (array de strings)
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
            $table->dropColumn('plan_id');
        });

        Schema::dropIfExists('plans');
    }
};
