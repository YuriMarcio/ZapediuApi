<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sellers: users with role='seller' get a unique invitational code
        Schema::table('users', function (Blueprint $table) {
            $table->string('seller_code', 50)->nullable()->unique()->after('role');
            $table->string('cpf', 20)->nullable()->after('phone');
        });

        // Company gains commercial identity and seller ownership
        Schema::table('companies', function (Blueprint $table) {
            $table->string('trade_name')->nullable()->after('name');
            $table->string('legal_name')->nullable()->after('trade_name');
            $table->string('document', 20)->nullable()->after('legal_name');
            $table->string('phone', 30)->nullable()->after('document');
            $table->string('whatsapp', 30)->nullable()->after('phone');
            $table->foreignId('seller_id')->nullable()->constrained('users')->nullOnDelete()->after('is_active');
        });

        // Store gains timezone
        Schema::table('stores', function (Blueprint $table) {
            $table->string('timezone', 50)->nullable()->default('America/Sao_Paulo')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });

        Schema::table('companies', function (Blueprint $table) {
            $table->dropForeign(['seller_id']);
            $table->dropColumn(['trade_name', 'legal_name', 'document', 'phone', 'whatsapp', 'seller_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_seller_code_unique');
            $table->dropColumn(['seller_code', 'cpf']);
        });
    }
};
