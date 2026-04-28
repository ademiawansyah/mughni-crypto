<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            // Model identification: which of the 3 independent models generated this signal
            $table->enum('model', ['counter_trend', 'pre_pump', 'momentum'])
                ->nullable()
                ->after('coin')
                ->comment('Trading model: counter_trend, pre_pump, or momentum');

            // Market regime context at time of signal generation
            $table->json('market_regime')
                ->nullable()
                ->after('model')
                ->comment('Market context: {market_regime, volatility, risk_level, btc_direction, market_strength}');

            // Per-model AI decision (if AI enabled for this model)
            $table->json('ai_decision')
                ->nullable()
                ->after('market_regime')
                ->comment('AI layer output: {action, confidence, reasoning, agreement}');

            // Index on model for filtering per-model signals
            $table->index('model');

            // Composite index for model lookups
            $table->index(['model', 'coin', 'timestamp']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->dropIndex(['model']);
            $table->dropIndex(['model', 'coin', 'timestamp']);
            $table->dropColumn('model');
            $table->dropColumn('market_regime');
            $table->dropColumn('ai_decision');
        });
    }
};
