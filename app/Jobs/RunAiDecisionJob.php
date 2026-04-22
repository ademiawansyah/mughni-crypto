<?php

namespace App\Jobs;

use App\Models\AiDecision;
use App\Models\GeneralConfig;
use App\Services\AI\AiAdvisorService;
use App\Services\MCP\McpResult;
use App\Services\MCP\MCPService;
use App\Services\Notification\NotificationService;
use App\Services\Trading\DecisionGuardrailService;
use App\Services\Trading\MTFScoringService;
use App\Services\Trading\PositionSizingService;
use App\Services\Trading\TradeLevelService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * RunAiDecisionJob
 *
 * Queued job that drives one full AI decision cycle for every configured coin.
 *
 * Pipeline (MTF-aware):
 *   1. For each coin, evaluate ALL configured timeframes through MCPService.
 *   2. Feed all per-timeframe McpResults into MTFScoringService to derive a
 *      deterministic preliminary_action and mtf_score.
 *   3. If preliminary_action is HOLD (or no timeframe passed MCP), skip AI.
 *   4. Determine the entry timeframe (finest-grained TF that passed MCP).
 *   5. Call AiAdvisorService in MTF-refinement mode — AI refines confidence only.
 *   6. Apply DecisionGuardrailService (includes MTF enforcement as Rule 1).
 *   7. Persist the final decision and trigger notification if eligible.
 *
 * No business logic lives here. All market/AI/guardrail logic is in the service layer.
 */
class RunAiDecisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted before it is marked as failed.
     */
    public int $tries = 2;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int>
     */
    public array $backoff = [60, 120];

    /**
     * @param  array<string>  $coins  Leave empty to read from GeneralConfig at runtime.
     * @param  array<string>  $timeframes  Leave empty to read from GeneralConfig at runtime.
     */
    public function __construct(
        private readonly array $coins = [],
        private readonly array $timeframes = [],
        private readonly string $executionId = '',
    ) {}

    /**
     * Execute the job.
     *
     * For each coin, collects MCP results across all timeframes, runs MTF scoring,
     * and delegates to the AI + guardrail pipeline when a non-HOLD signal is found.
     * Errors for individual coins are caught and logged so remaining coins always run.
     */
    public function handle(
        AiAdvisorService $advisorService,
        NotificationService $notificationService,
        MCPService $mcpService,
        DecisionGuardrailService $guardrailService,
        TradeLevelService $tradeLevelService,
        PositionSizingService $positionSizingService,
        MTFScoringService $mtfScoringService,
    ): void {
        Log::info('[RunAiDecisionJob] Execution started', [
            'execution_id' => $this->executionId,
        ]);

        $coins = $this->resolveCoins();
        $timeframes = $this->resolveTimeframes();

        foreach ($coins as $coin) {
            try {
                $this->processCoin(
                    $advisorService,
                    $notificationService,
                    $mcpService,
                    $guardrailService,
                    $tradeLevelService,
                    $positionSizingService,
                    $mtfScoringService,
                    $coin,
                    $timeframes,
                );
            } catch (Throwable $e) {
                Log::error('[RunAiDecisionJob] Unexpected failure — skipping coin', [
                    'execution_id' => $this->executionId,
                    'coin' => $coin,
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info('[RunAiDecisionJob] Execution completed', [
            'execution_id' => $this->executionId,
        ]);
    }

    /**
     * Process one coin using multi-timeframe consensus.
     *
     * Steps:
     *   1. Collect McpResult for every timeframe (null when MCP rejects it).
     *   2. Run MTFScoringService to derive preliminary_action + mtf_score.
     *   3. Skip when preliminary_action = HOLD or no TF passed MCP.
     *   4. Resolve entry timeframe (finest-grained passing TF).
     *   5. Call AI in MTF-refinement mode (AI refines confidence, not action).
     *   6. Apply guardrails (MTF enforcement is Rule 1 inside guardrail).
     *   7. Persist + notify.
     *
     * @param  array<string>  $timeframes
     */
    private function processCoin(
        AiAdvisorService $advisorService,
        NotificationService $notificationService,
        MCPService $mcpService,
        DecisionGuardrailService $guardrailService,
        TradeLevelService $tradeLevelService,
        PositionSizingService $positionSizingService,
        MTFScoringService $mtfScoringService,
        string $coin,
        array $timeframes,
    ): void {
        // --- Step 1: Collect per-timeframe MCP results ---
        /** @var array<string, McpResult|null> $mcpResults */
        $mcpResults = [];

        foreach ($timeframes as $timeframe) {
            $mcpResults[$timeframe] = $mcpService->evaluate($coin, $timeframe, $this->executionId);
        }

        // --- Step 2: MTF scoring ---
        $mtfResult = $mtfScoringService->score($mcpResults, $this->executionId);
        $timeframeSummary = $mtfScoringService->buildTimeframeSummary($mcpResults);

        // --- Step 3: Gate — skip when HOLD or no TF passed ---
        $passedResults = array_filter($mcpResults, fn(?McpResult $r) => $r !== null);

        if (empty($passedResults) || $mtfResult->preliminaryAction === 'HOLD') {
            Log::info('[RunAiDecisionJob] MTF preliminary action is HOLD — skipping AI', [
                'execution_id' => $this->executionId,
                'coin' => $coin,
                'mtf_score' => $mtfResult->mtfScore,
                'preliminary_action' => $mtfResult->preliminaryAction,
                'passed_timeframes' => array_keys($passedResults),
            ]);

            return;
        }

        // --- Step 4: Resolve entry timeframe (finest-grained TF that passed MCP) ---
        $entryTimeframe = $this->resolveEntryTimeframe($passedResults, $timeframes);
        $entryMcpResult = $passedResults[$entryTimeframe];

        // --- Step 5: AI call (refinement mode) ---
        $result = $advisorService->advise(
            coin: $coin,
            timeframe: $entryTimeframe,
            mcpResult: $entryMcpResult,
            mtfResult: $mtfResult,
            timeframeSummary: $timeframeSummary,
            executionId: $this->executionId,
        );

        if ($result === null) {
            Log::info('[RunAiDecisionJob] No indicator data — skipping persistence', [
                'execution_id' => $this->executionId,
                'coin' => $coin,
                'timeframe' => $entryTimeframe,
            ]);

            return;
        }

        $indicator = $result['indicator'];
        $decision = $result['decision'];
        $decisionBeforeGuardrail = $decision;

        // --- Step 6: Guardrail (Rule 1 = MTF enforcement) ---
        $decision = $guardrailService->apply(
            $decision,
            (float) $indicator->rsi,
            (string) $indicator->trend,
            $mtfResult,
        );

        $guardrailAccepted = in_array($decision['action'], ['BUY', 'SELL'], true)
            && $decision['confidence'] >= 55;

        $priceChange24h = $this->resolvePriceChange24h($indicator);

        $decision = $tradeLevelService->appendTradeLevels(
            $decision,
            (float) $indicator->price,
            $priceChange24h,
            $guardrailAccepted,
        );

        $decision = $positionSizingService->calculate($decision);

        Log::info('[RunAiDecisionJob] Guardrail evaluated', [
            'execution_id' => $this->executionId,
            'coin' => $coin,
            'timeframe' => $entryTimeframe,
            'before_action' => $decisionBeforeGuardrail['action'],
            'before_confidence' => $decisionBeforeGuardrail['confidence'],
            'after_action' => $decision['action'],
            'after_confidence' => $decision['confidence'],
            'decision_status' => $guardrailAccepted ? 'accepted' : 'rejected',
            'reason' => $decision['reason'],
            'mtf_score' => $mtfResult->mtfScore,
            'preliminary_action' => $mtfResult->preliminaryAction,
        ]);

        // --- Step 7: Persist + notify ---
        $isTradeCandidate = in_array($decision['action'], ['BUY', 'SELL'])
            && $decision['confidence'] >= 60;

        $startedAt = microtime(true);

        Log::info('[RunAiDecisionJob] Persisting decision', [
            'execution_id' => $this->executionId,
            'coin' => $coin,
            'timeframe' => $entryTimeframe,
            'action' => $decision['action'],
            'confidence' => $decision['confidence'],
            'decision_status' => $guardrailAccepted ? 'accepted' : 'rejected',
            'is_trade_candidate' => $isTradeCandidate,
        ]);

        AiDecision::create([
            'execution_id' => $this->executionId,
            'coin' => $coin,
            'timeframe' => $entryTimeframe,
            'timestamp' => $indicator->timestamp,
            'input_data' => [
                'price' => $indicator->price,
                'price_change_24h' => $priceChange24h,
                'rsi' => $indicator->rsi,
                'ema9' => $indicator->ema9,
                'ema21' => $indicator->ema21,
                'trend' => $indicator->trend,
                'timeframe' => $entryTimeframe,
                'entry' => $decision['entry'] ?? null,
                'take_profit' => $decision['take_profit'] ?? null,
                'stop_loss' => $decision['stop_loss'] ?? null,
                'mtf_score' => $mtfResult->mtfScore,
                'preliminary_action' => $mtfResult->preliminaryAction,
                'base_confidence' => $mtfResult->baseConfidence,
                'timeframe_summary' => $timeframeSummary,
            ],
            'action' => $decision['action'],
            'confidence' => $decision['confidence'],
            'is_trade_candidate' => $isTradeCandidate,
            'risk_level' => $decision['risk_level'],
            'reason' => $decision['reason'],
            'price_at_decision' => $indicator->price,
            'position_size' => $decision['position_size'] ?? null,
            'risk_amount' => $decision['risk_amount'] ?? null,
            'raw_response' => $result['raw_response'],
            'model_used' => $result['model_used'],
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        if ($isTradeCandidate) {
            $notificationService->sendTradeSignal([
                'execution_id' => $this->executionId,
                'coin' => $coin,
                'timeframe' => $entryTimeframe,
                'action' => $decision['action'],
                'confidence' => $decision['confidence'],
                'risk_level' => $decision['risk_level'],
                'reason' => $decision['reason'],
                'entry' => $decision['entry'] ?? null,
                'take_profit' => $decision['take_profit'] ?? null,
                'stop_loss' => $decision['stop_loss'] ?? null,
                'position_size' => $decision['position_size'] ?? null,
                'risk_amount' => $decision['risk_amount'] ?? null,
            ]);
        }

        Log::info('[RunAiDecisionJob] Decision persisted', [
            'execution_id' => $this->executionId,
            'coin' => $coin,
            'timeframe' => $entryTimeframe,
            'action' => $decision['action'],
            'confidence' => $decision['confidence'],
            'decision_status' => $guardrailAccepted ? 'accepted' : 'rejected',
        ]);
    }

    /**
     * Resolve the entry timeframe: the finest-grained (first-in-order) TF that passed MCP.
     *
     * The $orderedTimeframes list preserves the configured order, which is assumed to be
     * sorted from finest to coarsest (e.g. 1m, 5m, 15m, 30m, 60m).
     *
     * @param  array<string, McpResult>  $passedResults  Non-null McpResults keyed by TF label.
     * @param  array<string>  $orderedTimeframes  All configured TFs in order.
     * @return string The selected entry timeframe label.
     */
    private function resolveEntryTimeframe(array $passedResults, array $orderedTimeframes): string
    {
        foreach ($orderedTimeframes as $timeframe) {
            if (array_key_exists($timeframe, $passedResults)) {
                return $timeframe;
            }
        }

        // Fallback: use the first key from passed results (should not reach here).
        return array_key_first($passedResults);
    }

    /**
     * Resolve the list of coins to process.     *
     * Uses the constructor-provided list when available; otherwise falls back to the
     * application configuration so this job can be dispatched without arguments.
     *
     * @return array<string>
     */
    private function resolveCoins(): array
    {
        $coins = ! empty($this->coins)
            ? $this->coins
            : GeneralConfig::getCoins();

        return array_values(array_unique($coins));
    }

    /**
     * Resolve the list of timeframes to process.
     *
     * Uses the constructor-provided list when available; otherwise reads directly
     * from GeneralConfig so changes take effect without cache clears.
     *
     * @return array<string>
     */
    private function resolveTimeframes(): array
    {
        return ! empty($this->timeframes)
            ? $this->timeframes
            : GeneralConfig::getTimeframes();
    }

    private function resolvePriceChange24h(object $indicator): ?float
    {
        if (! method_exists($indicator, 'getAttributes')) {
            return null;
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $indicator->getAttributes();
        $priceChange24h = $attributes['price_change_24h'] ?? null;

        return is_numeric($priceChange24h)
            ? (float) $priceChange24h
            : null;
    }
}
