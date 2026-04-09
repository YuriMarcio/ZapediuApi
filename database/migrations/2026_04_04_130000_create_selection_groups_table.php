<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('selection_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->enum('display_type', ['custom', 'size', 'weight', 'type', 'presentation'])->default('custom');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('selection_group_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('selection_group_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('position')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('selection_group_id')
                ->nullable()
                ->after('category_id')
                ->constrained('selection_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('selection_group_id');
        });

        Schema::dropIfExists('selection_group_options');
        Schema::dropIfExists('selection_groups');
    }
};