<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variation_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->boolean('required')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('variation_group_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('variation_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('variation_group_id')
                ->nullable()
                ->after('selection_group_id')
                ->constrained('variation_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('variation_group_id');
        });

        Schema::dropIfExists('variation_group_options');
        Schema::dropIfExists('variation_groups');
    }
};