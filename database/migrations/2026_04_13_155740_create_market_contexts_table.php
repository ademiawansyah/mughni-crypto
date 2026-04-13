<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_contexts', function (Blueprint $table) {
            $table->id();
            $table->string('coin');
            $table->string('timeframe')->default('5m');
            $table->string('market_regime');
            $table->decimal('support_level', 18, 8)->nullable();
            $table->decimal('resistance_level', 18, 8)->nullable();
            $table->string('sentiment')->nullable();
            $table->string('source')->default('coingecko');
            $table->timestamp('timestamp')->comment('Market timestamp for the context');
            $table->timestamp('created_at')->useCurrent()->comment('Record creation timestamp');

            $table->index(['coin', 'timeframe', 'timestamp']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_contexts');
    }
};
