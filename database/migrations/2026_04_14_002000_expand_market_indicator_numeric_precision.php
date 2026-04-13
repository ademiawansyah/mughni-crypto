<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN price TYPE NUMERIC(30,8)');
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN volume TYPE NUMERIC(30,8)');
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN volume_ma TYPE NUMERIC(30,8)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN price TYPE NUMERIC(18,8)');
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN volume TYPE NUMERIC(18,8)');
        DB::statement('ALTER TABLE market_indicators ALTER COLUMN volume_ma TYPE NUMERIC(18,8)');
    }
};
