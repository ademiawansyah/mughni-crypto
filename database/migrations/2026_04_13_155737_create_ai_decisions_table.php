<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_decisions', function (Blueprint $table) {
            $table->id();
            $table->string('coin');
            $table->string('timeframe');
            $table->jsonb('input_data');
            $table->string('action');
            $table->integer('confidence');
            $table->string('risk_level');
            $table->text('reason');
            $table->decimal('price_at_decision', 18, 8);
            $table->string('market_trend')->nullable();
            $table->decimal('price_after_5m', 18, 8)->nullable();
            $table->decimal('price_after_15m', 18, 8)->nullable();
            $table->decimal('price_after_1h', 18, 8)->nullable();
            $table->decimal('max_profit', 18, 8)->nullable();
            $table->decimal('max_drawdown', 18, 8)->nullable();
            $table->string('result')->nullable();
            $table->string('model_used')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('timestamp')->comment('market timestamp when decision was made');
            $table->timestamp('created_at')->useCurrent()->comment('record creation timestamp');

            $table->index(['coin', 'timeframe', 'timestamp']);
            $table->index('confidence');
            $table->index('result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_decisions');
    }
};
