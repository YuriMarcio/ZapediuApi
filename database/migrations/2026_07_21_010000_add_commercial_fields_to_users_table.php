<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete()->after('company_id');
            $table->string('commercial_zone', 255)->nullable()->after('seller_code');
            $table->unsignedInteger('monthly_store_goal')->default(10)->after('commercial_zone');
            $table->boolean('is_active')->default(true)->after('is_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn(['manager_id', 'commercial_zone', 'monthly_store_goal', 'is_active']);
        });
    }
};
