<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('legal_name')->nullable()->after('name');
            $table->string('cnpj', 18)->nullable()->after('legal_name')->index();
            $table->string('phone', 30)->nullable()->after('whatsapp_phone');
            $table->string('zip_code', 10)->nullable()->after('description');
            $table->string('street')->nullable()->after('zip_code');
            $table->string('number', 20)->nullable()->after('street');
            $table->string('complement')->nullable()->after('number');
            $table->string('neighborhood')->nullable()->after('complement');
            $table->string('city')->nullable()->after('neighborhood');
            $table->string('state', 2)->nullable()->after('city');
            $table->json('business_hours')->nullable()->after('settings');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn([
                'legal_name',
                'cnpj',
                'phone',
                'zip_code',
                'street',
                'number',
                'complement',
                'neighborhood',
                'city',
                'state',
                'business_hours',
            ]);
        });
    }
};