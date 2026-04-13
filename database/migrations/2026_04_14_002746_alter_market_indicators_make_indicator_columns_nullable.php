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
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN rsi DROP NOT NULL');
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN ema9 DROP NOT NULL');
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN ema21 DROP NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN rsi SET NOT NULL');
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN ema9 SET NOT NULL');
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN ema21 SET NOT NULL');
    }
};
