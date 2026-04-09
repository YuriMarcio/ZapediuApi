<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
            $table->json('product_ids')->nullable()->after('store_id');
        });

        DB::table('orders')
            ->orderBy('id')
            ->chunkById(100, function ($orders): void {
                foreach ($orders as $order) {
                    $productIds = DB::table('order_items')
                        ->where('order_id', $order->id)
                        ->whereNotNull('product_id')
                        ->orderBy('id')
                        ->pluck('product_id')
                        ->map(fn ($id) => (int) $id)
                        ->values()
                        ->all();

                    $userId = $this->resolveOrCreateCustomerUserId($order);

                    DB::table('orders')
                        ->where('id', $order->id)
                        ->update([
                            'user_id' => $userId,
                            'product_ids' => $productIds === [] ? null : json_encode($productIds, JSON_THROW_ON_ERROR),
                        ]);
                }
            });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['channel', 'whatsapp_clicks']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('channel')->default('whatsapp')->index();
            $table->unsignedInteger('whatsapp_clicks')->default(0);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('product_ids');
        });
    }

    private function resolveOrCreateCustomerUserId(object $order): ?int
    {
        $phone = $this->normalizePhone($order->customer_phone ?? null);
        $email = $this->normalizeEmail($order->customer_name ?? null, $phone, $order->code ?? null);

        $user = null;

        if ($phone !== null) {
            $user = DB::table('users')->where('phone', $phone)->first();
        }

        if ($user === null && $email !== null) {
            $user = DB::table('users')->where('email', $email)->first();
        }

        if ($user !== null) {
            return (int) $user->id;
        }

        if ($email === null) {
            return null;
        }

        return (int) DB::table('users')->insertGetId([
            'company_id' => $order->company_id,
            'name' => $order->customer_name ?: 'Cliente '.($phone ?? $order->code ?? Str::random(6)),
            'email' => $email,
            'phone' => $phone,
            'password' => Hash::make(Str::random(32)),
            'is_admin' => false,
            'role' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function normalizePhone(mixed $phone): ?string
    {
        $normalized = preg_replace('/\D+/', '', (string) ($phone ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeEmail(mixed $name, ?string $phone, mixed $orderCode): ?string
    {
        if ($phone !== null) {
            return 'cliente-'.$phone.'@deliveryzap.local';
        }

        $code = Str::lower(Str::slug((string) ($orderCode ?? $name ?? Str::random(6))));

        return $code !== '' ? 'cliente-'.$code.'@deliveryzap.local' : null;
    }
};