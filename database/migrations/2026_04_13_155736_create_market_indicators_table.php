<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_indicators', function (Blueprint $table) {
            $table->id();
            $table->string('coin')->index();
            $table->string('timeframe')->index();
            $table->decimal('price', 18, 8);
            $table->float('rsi');
            $table->float('ema9');
            $table->float('ema21');
            $table->decimal('volume', 18, 8);
            $table->decimal('volume_ma', 18, 8)->nullable();
            $table->string('trend');
            $table->float('volatility')->nullable();
            $table->string('source')->default('coingecko');
            $table->timestamp('timestamp')->index()->comment('market data timestamp');
            $table->timestamp('created_at')->useCurrent()->comment('record creation timestamp');


            $table->index(['coin', 'timeframe', 'timestamp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_indicators');
    }
};
