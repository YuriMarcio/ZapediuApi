<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optional_flows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('optional_flow_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('optional_flow_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('description')->nullable();
            $table->string('trigger_when')->nullable();
            $table->string('customer_hint')->nullable();
            $table->boolean('is_required')->default(false);
            $table->enum('charge_type', ['free', 'paid'])->default('free');
            $table->unsignedInteger('min_select')->default(0);
            $table->unsignedInteger('max_select')->default(1);
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
        });

        Schema::create('optional_flow_step_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('optional_flow_step_id')->constrained()->cascadeOnDelete();
            $table->enum('source_type', ['custom', 'product', 'category'])->default('custom');
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(1);
            $table->timestamps();
        });

        Schema::create('optional_flow_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('optional_flow_id')->constrained()->cascadeOnDelete();
            $table->morphs('assignable');
            $table->timestamps();
            $table->unique(['optional_flow_id', 'assignable_type', 'assignable_id'], 'optional_flow_assignment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optional_flow_assignments');
        Schema::dropIfExists('optional_flow_step_options');
        Schema::dropIfExists('optional_flow_steps');
        Schema::dropIfExists('optional_flows');
    }
};
