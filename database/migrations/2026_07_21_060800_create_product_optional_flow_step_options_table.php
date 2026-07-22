<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_optional_flow_step_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('optional_flow_step_option_id');
            $table->timestamps();

            $table->foreign('optional_flow_step_option_id', 'pofso_option_id_fk')
                ->references('id')->on('optional_flow_step_options')->cascadeOnDelete();
            $table->unique(['product_id', 'optional_flow_step_option_id'], 'product_flow_option_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_optional_flow_step_options');
    }
};
