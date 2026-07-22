<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optional_flow_step_option_sizes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('optional_flow_step_option_id');
            $table->foreignId('store_pizza_size_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2)->default(0);
            $table->timestamps();

            $table->foreign('optional_flow_step_option_id', 'ofsos_option_id_fk')
                ->references('id')->on('optional_flow_step_options')->cascadeOnDelete();
            $table->unique(['optional_flow_step_option_id', 'store_pizza_size_id'], 'flow_option_size_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('optional_flow_step_option_sizes');
    }
};
