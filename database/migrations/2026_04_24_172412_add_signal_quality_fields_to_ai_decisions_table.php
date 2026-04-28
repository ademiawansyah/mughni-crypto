<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            // Trigger context
            $table->string('trigger_timeframe')->nullable()->after('timeframe');
            $table->unsignedInteger('trigger_score')->nullable()->after('trigger_timeframe');

            // MTF score dual-track
            $table->decimal('mtf_raw_score', 8, 4)->nullable()->after('trigger_score');
            $table->decimal('mtf_effective_score', 8, 4)->nullable()->after('mtf_raw_score');

            // MTF context
            $table->string('mtf_direction')->nullable()->after('mtf_effective_score');
            $table->string('alignment')->nullable()->after('mtf_direction');
            $table->string('mode')->nullable()->after('alignment');

            // AI decision flags
            $table->boolean('ai_used')->nullable()->after('mode');
            $table->boolean('ai_agreement')->nullable()->after('ai_used');

            // Composite index for performance analysis queries
            $table->index(['coin', 'trigger_timeframe', 'alignment', 'created_at'], 'ai_decisions_signal_quality_idx');
        });
    }

    public function down(): void
    {
        Schema::table('ai_decisions', function (Blueprint $table) {
            $table->dropIndex('ai_decisions_signal_quality_idx');
            $table->dropColumn([
                'trigger_timeframe',
                'trigger_score',
                'mtf_raw_score',
                'mtf_effective_score',
                'mtf_direction',
                'alignment',
                'mode',
                'ai_used',
                'ai_agreement',
            ]);
        });
    }
};
