<?php

namespace App\Jobs;

use App\Models\AiDecision;
use App\Models\GeneralConfig;
use App\Services\AI\AiAdvisorService;
use App\Services\MCP\MCPService;
use App\Services\Notification\NotificationService;
use App\Services\Trading\DecisionGuardrailService;
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
    ): void {
        $coins = $this->resolveCoins();
        $timeframes = $this->resolveTimeframes();

        foreach ($coins as $coin) {
            foreach ($timeframes as $timeframe) {
                try {
                    $this->processOne($advisorService, $notificationService, $mcpService, $guardrailService, $coin, $timeframe);
                } catch (Throwable $e) {
                    Log::error('[RunAiDecisionJob] Unexpected failure — skipping coin', [
                        'coin' => $coin,
                        'timeframe' => $timeframe,
                        'exception' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                }
            }
        }
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
        string $coin,
        string $timeframe
    ): void {
        // MCP pre-filter gate — skips AI for low-quality or duplicate signals
        $mcpResult = $mcpService->evaluate($coin, $timeframe);

        if ($mcpResult === null) {
            return;
        }

        $result = $advisorService->advise($coin, $timeframe, $mcpResult);

        if ($result === null) {
            Log::info('[RunAiDecisionJob] No indicator data, skipping persistence', [
                'coin' => $coin,
                'timeframe' => $timeframe,
            ]);

            return;
        }

        $indicator = $result['indicator'];
        $decision = $result['decision'];

        $decision = $guardrailService->apply(
            $decision,
            (float) $indicator->rsi,
            (string) $indicator->trend,
        );

        // A trade candidate is a high-confidence BUY or SELL — the only signals worth acting on.
        $isTradeCandidate = in_array($decision['action'], ['BUY', 'SELL'])
            && $decision['confidence'] >= 60;

        $startedAt = microtime(true);

        Log::info('[RunAiDecisionJob] Persisting decision', [
            'coin' => $coin,
            'timeframe' => $timeframe,
            'action' => $decision['action'],
            'confidence' => $decision['confidence'],
            'is_trade_candidate' => $isTradeCandidate,
        ]);

        AiDecision::create([
            'coin' => $coin,
            'timeframe' => $timeframe,
            'timestamp' => $indicator->timestamp,
            'input_data' => [
                'price' => $indicator->price,
                'rsi' => $indicator->rsi,
                'ema9' => $indicator->ema9,
                'ema21' => $indicator->ema21,
                'trend' => $indicator->trend,
                'timeframe' => $timeframe,
            ],
            'action' => $decision['action'],
            'confidence' => $decision['confidence'],
            'is_trade_candidate' => $isTradeCandidate,
            'risk_level' => $decision['risk_level'],
            'reason' => $decision['reason'],
            'price_at_decision' => $indicator->price,
            'raw_response' => $result['raw_response'],
            'model_used' => $result['model_used'],
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

        if ($isTradeCandidate) {
            $notificationService->sendTradeSignal([
                'coin' => $coin,
                'timeframe' => $timeframe,
                'action' => $decision['action'],
                'confidence' => $decision['confidence'],
                'risk_level' => $decision['risk_level'],
                'reason' => $decision['reason'],
                'entry' => $decision['entry'] ?? null,
                'take_profit' => $decision['take_profit'] ?? null,
                'stop_loss' => $decision['stop_loss'] ?? null,
            ]);
        }

        Log::info('[RunAiDecisionJob] Decision persisted', [
            'coin' => $coin,
            'timeframe' => $timeframe,
            'action' => $decision['action'],
            'confidence' => $decision['confidence'],
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
}
