<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('phone', 30)->index();
            $table->string('label')->nullable();
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('user_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('street')->nullable();
            $table->string('number', 40)->nullable();
            $table->string('district')->nullable();
            $table->string('complement')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 10)->nullable();
            $table->string('zip_code', 20)->nullable();
            $table->text('formatted')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_primary')->default(false)->index();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('source')->default('whatsapp')->after('code')->index();
        });

        DB::table('users')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->chunkById(100, function ($users): void {
                foreach ($users as $user) {
                    $exists = DB::table('user_phones')
                        ->where('user_id', $user->id)
                        ->where('phone', $user->phone)
                        ->exists();

                    if (! $exists) {
                        DB::table('user_phones')->insert([
                            'user_id' => $user->id,
                            'phone' => $user->phone,
                            'label' => 'principal',
                            'is_primary' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });

        DB::table('orders')
            ->whereNotNull('user_id')
            ->whereNotNull('customer_address')
            ->orderBy('id')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $exists = DB::table('user_addresses')
                        ->where('user_id', $order->user_id)
                        ->where('formatted', $order->customer_address)
                        ->exists();

                    if (! $exists) {
                        DB::table('user_addresses')->insert([
                            'user_id' => $order->user_id,
                            'street' => $order->customer_address,
                            'formatted' => $order->customer_address,
                            'notes' => $order->notes,
                            'is_primary' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });

        DB::table('orders')->where('status', 'new')->update(['status' => 'pending']);
        DB::table('orders')->where('status', 'confirmed')->update(['status' => 'accepted']);
        DB::table('orders')->where('status', 'out_for_delivery')->update(['status' => 'delivering']);
        DB::table('orders')->where('status', 'delivered')->update(['status' => 'done']);

        DB::table('orders')
            ->where('source', 'whatsapp')
            ->whereNotNull('raw_payload')
            ->update(['source' => DB::raw("CASE WHEN JSON_EXTRACT(raw_payload, '$.source') IS NOT NULL THEN JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.source')) ELSE 'whatsapp' END")]);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['customer_name', 'customer_phone', 'customer_address']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable()->index();
            $table->text('customer_address')->nullable();
            $table->dropColumn('source');
        });

        DB::table('orders')->where('status', 'pending')->update(['status' => 'new']);
        DB::table('orders')->where('status', 'accepted')->update(['status' => 'confirmed']);
        DB::table('orders')->where('status', 'delivering')->update(['status' => 'out_for_delivery']);
        DB::table('orders')->where('status', 'done')->update(['status' => 'delivered']);

        Schema::dropIfExists('user_addresses');
        Schema::dropIfExists('user_phones');
    }
};