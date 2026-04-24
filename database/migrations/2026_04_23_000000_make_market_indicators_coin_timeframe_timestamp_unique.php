<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_indicators', function (Blueprint $table) {
            $table->dropIndex('market_indicators_coin_timeframe_timestamp_index');
            $table->unique(['coin', 'timeframe', 'timestamp'], 'market_indicators_coin_timeframe_timestamp_unique');
        });
    }

    public function down(): void
    {
        Schema::table('market_indicators', function (Blueprint $table) {
            $table->dropUnique('market_indicators_coin_timeframe_timestamp_unique');
            $table->index(['coin', 'timeframe', 'timestamp'], 'market_indicators_coin_timeframe_timestamp_index');
        });
    }
};
