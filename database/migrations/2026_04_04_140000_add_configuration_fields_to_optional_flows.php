<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optional_flow_steps', function (Blueprint $table): void {
            $table->enum('items_source', ['system', 'merchant'])
                ->default('system')
                ->after('customer_hint');
            $table->boolean('allow_price_override')
                ->default(false)
                ->after('items_source');
        });

        Schema::table('optional_flow_step_options', function (Blueprint $table): void {
            $table->decimal('base_price', 10, 2)
                ->default(0)
                ->after('description');
            $table->decimal('merchant_price', 10, 2)
                ->nullable()
                ->after('base_price');
        });

        DB::table('optional_flow_step_options')->update([
            'base_price' => DB::raw('price'),
        ]);
    }

    public function down(): void
    {
        Schema::table('optional_flow_step_options', function (Blueprint $table): void {
            $table->dropColumn(['base_price', 'merchant_price']);
        });

        Schema::table('optional_flow_steps', function (Blueprint $table): void {
            $table->dropColumn(['items_source', 'allow_price_override']);
        });
    }
};