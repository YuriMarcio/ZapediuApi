<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('optional_flow_step_options', function (Blueprint $table): void {
            $table->string('image_url')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('optional_flow_step_options', function (Blueprint $table): void {
            $table->dropColumn('image_url');
        });
    }
};
