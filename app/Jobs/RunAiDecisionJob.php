<?php

namespace App\Jobs;

use App\Models\AiDecision;
use App\Models\GeneralConfig;
use App\Services\AI\AiAdvisorService;
use App\Services\MCP\MCPService;
use App\Services\Notification\NotificationService;
use App\Services\Trading\DecisionGuardrailService;
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
 * Responsibilities:
 *   1. Iterate over all configured coins and timeframes.
 *   2. Run each coin through MCPService — skip the AI call if MCP returns null.
 *   3. Delegate to AiAdvisorService only for coins that pass the MCP pre-filter.
 *   4. Apply deterministic guardrails to the calibrated AI decision.
 *   5. Persist the resulting decision to the `ai_decisions` table.
 *   6. Continue processing remaining coins if one fails — never crash the whole job.
 *   7. Default to a HOLD decision if the AI service is unavailable.
 *
 * No business logic lives here. All market/AI logic is in the service layer.
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
     * Loops every coin × timeframe combination and persists an AI decision for each.
     * Errors for individual coins are caught, logged, and skipped so remaining coins
     * are always processed.
     */
    public function handle(
        AiAdvisorService $advisorService,
        NotificationService $notificationService,
        MCPService $mcpService,
        DecisionGuardrailService $guardrailService,
        TradeLevelService $tradeLevelService,
        PositionSizingService $positionSizingService,
    ): void {
        Log::info('[RunAiDecisionJob] Execution started', [
            'execution_id' => $this->executionId,
        ]);

        $coins = $this->resolveCoins();
        $timeframes = $this->resolveTimeframes();

        foreach ($coins as $coin) {
            foreach ($timeframes as $timeframe) {
                try {
                    $this->processOne($advisorService, $notificationService, $mcpService, $guardrailService, $tradeLevelService, $positionSizingService, $coin, $timeframe);
                } catch (Throwable $e) {
                    Log::error('[RunAiDecisionJob] Unexpected failure — skipping coin', [
                        'execution_id' => $this->executionId,
                        'coin' => $coin,
                        'timeframe' => $timeframe,
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }

        Log::info('[RunAiDecisionJob] Execution completed', [
            'execution_id' => $this->executionId,
        ]);
    }

    /**
     * Run one coin/timeframe cycle through MCP pre-filter then the AI advisor.
     *
     * MCPService is evaluated first. If it returns null the coin is skipped entirely
     * and no AI call is made. Only coins that pass the MCP score threshold and are
     * outside the cooldown window are forwarded to AiAdvisorService.
     */
    private function processOne(
        AiAdvisorService $advisorService,
        NotificationService $notificationService,
        MCPService $mcpService,
        DecisionGuardrailService $guardrailService,
        TradeLevelService $tradeLevelService,
        PositionSizingService $positionSizingService,
        string $coin,
        string $timeframe
    ): void {
        // MCP pre-filter gate — skips AI for low-quality or duplicate signals
        $mcpResult = $mcpService->evaluate($coin, $timeframe, $this->executionId);

        if ($mcpResult === null) {
            return;
        }

        $result = $advisorService->advise($coin, $timeframe, $mcpResult, $this->executionId);

        if ($result === null) {
            Log::info('[RunAiDecisionJob] No indicator data, skipping persistence', [
                'execution_id' => $this->executionId,
                'coin' => $coin,
                'timeframe' => $timeframe,
            ]);

            return;
        }

        $indicator = $result['indicator'];
        $decision = $result['decision'];

        $decisionBeforeGuardrail = $decision;

        $decision = $guardrailService->apply(
            $decision,
            (float) $indicator->rsi,
            (string) $indicator->trend,
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
            'timeframe' => $timeframe,
            'before_action' => $decisionBeforeGuardrail['action'],
            'before_confidence' => $decisionBeforeGuardrail['confidence'],
            'after_action' => $decision['action'],
            'after_confidence' => $decision['confidence'],
            'decision_status' => $guardrailAccepted ? 'accepted' : 'rejected',
            'reason' => $decision['reason'],
        ]);

        // A trade candidate is a high-confidence BUY or SELL — the only signals worth acting on.
        $isTradeCandidate = in_array($decision['action'], ['BUY', 'SELL'])
            && $decision['confidence'] >= 60;

        $startedAt = microtime(true);

        Log::info('[RunAiDecisionJob] Persisting decision', [
            'execution_id' => $this->executionId,
            'coin' => $coin,
            'timeframe' => $timeframe,
            'action' => $decision['action'],
            'confidence' => $decision['confidence'],
            'decision_status' => $guardrailAccepted ? 'accepted' : 'rejected',
            'is_trade_candidate' => $isTradeCandidate,
        ]);

        AiDecision::create([
            'execution_id' => $this->executionId,
            'coin' => $coin,
            'timeframe' => $timeframe,
            'timestamp' => $indicator->timestamp,
            'input_data' => [
                'price' => $indicator->price,
                'price_change_24h' => $priceChange24h,
                'rsi' => $indicator->rsi,
                'ema9' => $indicator->ema9,
                'ema21' => $indicator->ema21,
                'trend' => $indicator->trend,
                'timeframe' => $timeframe,
                'entry' => $decision['entry'] ?? null,
                'take_profit' => $decision['take_profit'] ?? null,
                'stop_loss' => $decision['stop_loss'] ?? null,
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
                'timeframe' => $timeframe,
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
            'timeframe' => $timeframe,
            'action' => $decision['action'],
            'confidence' => $decision['confidence'],
            'decision_status' => $guardrailAccepted ? 'accepted' : 'rejected',
        ]);
    }

    /**
     * Resolve the list of coins to process.
     *
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
