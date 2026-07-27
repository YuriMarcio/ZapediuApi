<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->where('slug', 'diario')->update([
            'name' => 'Plano Zapediu',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('plans')->where('slug', 'diario')->update([
            'name' => 'Diário',
            'updated_at' => now(),
        ]);
    }
};
