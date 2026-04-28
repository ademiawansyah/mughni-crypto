<?php

namespace Tests\Feature\Filament\Widgets;

use App\Filament\Widgets\DecisionAuthorityChainWidget;
use App\Filament\Widgets\ExecutionTraceabilityTableWidget;
use App\Filament\Widgets\McpGateHealthWidget;
use App\Filament\Widgets\NotificationEligibilityWidget;
use App\Filament\Widgets\PerModelScorecardWidget;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class ModelOpsWidgetsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_mcp_gate_health_widget_renders_recent_health_metrics(): void
    {
        $now = now();

        DB::table('market_contexts')->insert([
            [
                'coin' => 'bitcoin',
                'timeframe' => 'summary',
                'market_regime' => 'TRENDING_UP',
                'source' => 'mtf',
                'execution_id' => 'exec-1',
                'mcp_passed' => true,
                'mcp_score' => 82,
                'mcp_candidate' => 'BUY',
                'timestamp' => $now->copy()->subMinutes(10),
            ],
            [
                'coin' => 'ethereum',
                'timeframe' => 'summary',
                'market_regime' => 'TRENDING_UP',
                'source' => 'mtf_service',
                'execution_id' => 'exec-2',
                'mcp_passed' => false,
                'mcp_score' => 40,
                'mcp_candidate' => 'SELL',
                'timestamp' => $now->copy()->subMinutes(5),
            ],
            [
                'coin' => 'solana',
                'timeframe' => 'summary',
                'market_regime' => 'RANGING',
                'source' => 'mtf',
                'execution_id' => 'exec-3',
                'mcp_passed' => true,
                'mcp_score' => 90,
                'mcp_candidate' => 'BUY',
                'timestamp' => $now,
            ],
        ]);

        Livewire::test(McpGateHealthWidget::class)
            ->assertSee('MCP Pass Rate')
            ->assertSee('66.7%')
            ->assertSee('Average MCP Score')
            ->assertSee('70.7')
            ->assertSee('Dominant Candidate')
            ->assertSee('BUY');
    }

    public function test_decision_authority_chain_widget_renders_mcp_to_final_status_rows(): void
    {
        $now = now();

        DB::table('market_contexts')->insert([
            [
                'coin' => 'bitcoin',
                'timeframe' => 'summary',
                'market_regime' => 'TRENDING_UP',
                'source' => 'mtf',
                'execution_id' => 'exec-chain-1111',
                'mcp_candidate' => 'BUY',
                'preliminary_action' => 'BUY',
                'fusion_ai_action' => 'BUY',
                'fusion_final_action' => 'BUY',
                'final_action' => 'BUY',
                'decision_status' => 'accepted',
                'timestamp' => $now,
            ],
        ]);

        Livewire::test(DecisionAuthorityChainWidget::class)
            ->assertSee('Decision Authority Chain')
            ->assertSee('bitcoin')
            ->assertSee('BUY')
            ->assertSee('PASSED')
            ->assertSee('ACCEPTED')
            ->assertSee('exec-cha');
    }

    public function test_execution_traceability_widget_is_keyed_by_execution_id(): void
    {
        $now = now();
        $executionA = (string) Str::uuid();
        $executionB = (string) Str::uuid();

        DB::table('ai_decisions')->insert([
            $this->decisionRow($executionA, 'counter_trend', 'bitcoin', 'BUY', 78, $now->copy()->subMinutes(5)),
            $this->decisionRow($executionA, 'pre_pump', 'ethereum', 'SELL', 71, $now->copy()->subMinutes(4)),
            $this->decisionRow($executionB, 'momentum', 'solana', 'HOLD', 55, $now->copy()->subMinutes(2)),
        ]);

        Livewire::test(ExecutionTraceabilityTableWidget::class)
            ->assertSee('Execution Traceability')
            ->assertSee($executionA)
            ->assertSee('2')
            ->assertSee('1')
            ->assertSee($executionB);
    }

    public function test_per_model_scorecard_widget_renders_three_model_stats(): void
    {
        $now = now();

        DB::table('ai_decisions')->insert([
            $this->decisionRow((string) Str::uuid(), 'counter_trend', 'bitcoin', 'BUY', 80, $now->copy()->subMinutes(9), true),
            $this->decisionRow((string) Str::uuid(), 'pre_pump', 'ethereum', 'SELL', 70, $now->copy()->subMinutes(7), false),
            $this->decisionRow((string) Str::uuid(), 'momentum', 'solana', 'HOLD', 60, $now->copy()->subMinutes(3), true),
        ]);

        Livewire::test(PerModelScorecardWidget::class)
            ->assertSee('Counter Trend')
            ->assertSee('Pre Pump')
            ->assertSee('Momentum');
    }

    public function test_notification_eligibility_widget_counts_unique_and_duplicate_signals(): void
    {
        $now = now();

        DB::table('market_contexts')->insert([
            [
                'coin' => 'bitcoin',
                'timeframe' => 'summary',
                'market_regime' => 'TRENDING_UP',
                'source' => 'mtf',
                'execution_id' => 'exec-notify-1',
                'final_action' => 'BUY',
                'decision_status' => 'accepted',
                'timestamp' => $now->copy()->subMinutes(10),
            ],
            [
                'coin' => 'bitcoin',
                'timeframe' => 'summary',
                'market_regime' => 'TRENDING_UP',
                'source' => 'mtf_service',
                'execution_id' => 'exec-notify-2',
                'final_action' => 'BUY',
                'decision_status' => 'accepted',
                'timestamp' => $now->copy()->subMinutes(10),
            ],
            [
                'coin' => 'ethereum',
                'timeframe' => 'summary',
                'market_regime' => 'TRENDING_DOWN',
                'source' => 'mtf',
                'execution_id' => 'exec-notify-3',
                'final_action' => 'SELL',
                'decision_status' => 'accepted',
                'timestamp' => $now->copy()->subMinutes(6),
            ],
            [
                'coin' => 'solana',
                'timeframe' => 'summary',
                'market_regime' => 'RANGING',
                'source' => 'mtf',
                'execution_id' => 'exec-notify-4',
                'final_action' => 'HOLD',
                'decision_status' => 'rejected',
                'timestamp' => $now,
            ],
        ]);

        Livewire::test(NotificationEligibilityWidget::class)
            ->assertSee('Eligible BUY/SELL (Unique)')
            ->assertSee('1')
            ->assertSee('Duplicate Groups')
            ->assertSee('Accepted vs Rejected Rows')
            ->assertSee('3 / 1');
    }

    /**
     * @return array<string, mixed>
     */
    private function decisionRow(
        string $executionId,
        string $model,
        string $coin,
        string $action,
        int $confidence,
        \DateTimeInterface $timestamp,
        bool $aiUsed = false,
    ): array {
        return [
            'execution_id' => $executionId,
            'coin' => $coin,
            'model' => $model,
            'timeframe' => '15m',
            'input_data' => json_encode(['seed' => true], JSON_THROW_ON_ERROR),
            'action' => $action,
            'confidence' => $confidence,
            'risk_level' => 'LOW',
            'reason' => 'test-row',
            'price_at_decision' => 100.10000000,
            'ai_used' => $aiUsed,
            'timestamp' => $timestamp,
        ];
    }
}
