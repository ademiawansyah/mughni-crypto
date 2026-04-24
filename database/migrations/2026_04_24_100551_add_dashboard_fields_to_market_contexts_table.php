<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_contexts', function (Blueprint $table) {
            $table->string('execution_id')->nullable()->after('source');

            $table->boolean('mcp_passed')->nullable()->after('execution_id');
            $table->integer('mcp_score')->nullable()->after('mcp_passed');
            $table->string('mcp_candidate')->nullable()->after('mcp_score');
            $table->string('mcp_timeframe')->nullable()->after('mcp_candidate');
            $table->string('mcp_reason')->nullable()->after('mcp_timeframe');

            $table->decimal('mtf_score', 10, 4)->nullable()->after('mcp_reason');
            $table->string('preliminary_action')->nullable()->after('mtf_score');
            $table->integer('base_confidence')->nullable()->after('preliminary_action');
            $table->json('role_timeframes')->nullable()->after('base_confidence');
            $table->text('timeframe_summary')->nullable()->after('role_timeframes');

            $table->string('fusion_ai_action')->nullable()->after('timeframe_summary');
            $table->integer('fusion_ai_confidence')->nullable()->after('fusion_ai_action');
            $table->string('fusion_final_action')->nullable()->after('fusion_ai_confidence');
            $table->integer('fusion_confidence_adjusted')->nullable()->after('fusion_final_action');

            $table->string('final_action')->nullable()->after('fusion_confidence_adjusted');
            $table->integer('final_confidence')->nullable()->after('final_action');
            $table->string('decision_status')->nullable()->after('final_confidence');
        });
    }

    public function down(): void
    {
        Schema::table('market_contexts', function (Blueprint $table) {
            $table->dropColumn([
                'execution_id',
                'mcp_passed',
                'mcp_score',
                'mcp_candidate',
                'mcp_timeframe',
                'mcp_reason',
                'mtf_score',
                'preliminary_action',
                'base_confidence',
                'role_timeframes',
                'timeframe_summary',
                'fusion_ai_action',
                'fusion_ai_confidence',
                'fusion_final_action',
                'fusion_confidence_adjusted',
                'final_action',
                'final_confidence',
                'decision_status',
            ]);
        });
    }
};
