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
        Schema::table('stores', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('category')->nullable()->after('description')->index();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unsignedInteger('whatsapp_clicks')->default(0)->after('channel');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('category');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('whatsapp_clicks');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });

        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
