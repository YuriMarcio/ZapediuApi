<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('coverage_area');
            $table->string('city');
            $table->string('state', 2);
            $table->string('status')->default('active')->index();
            $table->foreignId('commercial_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('delivery_manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('whatsapp_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('flowbridge_instance_id')->unique();
            $table->string('phone_number')->nullable()->index();
            $table->string('status')->default('connecting')->index();
            $table->string('webhook_secret', 120)->unique();
            $table->timestamps();
        });

        Schema::table('users', fn (Blueprint $table) => $table->foreignId('operation_id')->nullable()->constrained()->nullOnDelete()->after('manager_id'));
        Schema::table('companies', fn (Blueprint $table) => $table->foreignId('operation_id')->nullable()->constrained()->nullOnDelete()->after('manager_id'));
        Schema::table('leads', fn (Blueprint $table) => $table->foreignId('operation_id')->nullable()->constrained()->nullOnDelete()->after('manager_id'));
        Schema::table('couriers', fn (Blueprint $table) => $table->foreignId('operation_id')->nullable()->constrained()->nullOnDelete()->after('id'));
        Schema::table('orders', fn (Blueprint $table) => $table->foreignId('operation_id')->nullable()->constrained()->nullOnDelete()->after('company_id'));
        Schema::table('deliveries', fn (Blueprint $table) => $table->foreignId('operation_id')->nullable()->constrained()->nullOnDelete()->after('id'));
    }

    public function down(): void
    {
        foreach (['deliveries', 'orders', 'couriers', 'leads', 'companies', 'users'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) { $table->dropConstrainedForeignId('operation_id'); });
        }
        Schema::dropIfExists('whatsapp_sessions');
        Schema::dropIfExists('operations');
    }
};
