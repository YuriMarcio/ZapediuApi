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
        Schema::table('companies', function (Blueprint $table) {
            $table->string('flowbridge_instance_id')->nullable()->index()->after('zapi_webhook_token');
            $table->string('flowbridge_webhook_secret')->nullable()->after('flowbridge_instance_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['flowbridge_instance_id', 'flowbridge_webhook_secret']);
        });
    }
};
