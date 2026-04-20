<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN volume DROP NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('UPDATE market_indicators SET volume = 0 WHERE volume IS NULL');
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN volume SET NOT NULL');
    }
};
