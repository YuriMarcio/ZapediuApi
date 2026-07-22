<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_pizza_sizes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedTinyInteger('slice_count')->nullable();
            $table->unsignedTinyInteger('max_flavors')->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedTinyInteger('position')->default(1);
            $table->timestamps();
            $table->unique(['store_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_pizza_sizes');
    }
};
