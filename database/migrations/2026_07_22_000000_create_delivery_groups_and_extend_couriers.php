<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('operation_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('whatsapp_group_jid')->unique();
            $table->string('invite_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('couriers', function (Blueprint $table): void {
            $table->foreignId('delivery_group_id')->nullable()->after('operation_id')->constrained('delivery_groups')->nullOnDelete();
            $table->string('profile_image_path')->nullable()->after('phone');
            $table->string('driver_license_path')->nullable()->after('cnh_number');
            $table->string('registration_status')->default('pending_confirmation')->index()->after('status');
            $table->timestamp('confirmed_at')->nullable()->after('registration_status');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('delivery_group_id')->nullable()->after('courier_id')->constrained('delivery_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('delivery_group_id'));
        Schema::table('couriers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('delivery_group_id');
            $table->dropColumn(['profile_image_path', 'driver_license_path', 'registration_status', 'confirmed_at']);
        });
        Schema::dropIfExists('delivery_groups');
    }
};
