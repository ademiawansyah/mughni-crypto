<?php

namespace App\Jobs;

use App\Models\AiDecision;
use App\Services\AI\AiAdvisorService;
use Carbon\Carbon;
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
 *   2. Delegate to AiAdvisorService for each coin/timeframe pair.
 *   3. Persist the resulting decision to the `ai_decisions` table.
 *   4. Continue processing remaining coins if one fails — never crash the whole job.
 *   5. Default to a HOLD decision if the AI service is unavailable.
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
     * The list of coins to process during this cycle.
     * Defaults to config/market.php when not supplied explicitly.
     *
     * @param  array<string>  $coins
     * @param  array<string>  $timeframes
     */
    public function __construct(
        private readonly array $coins = [],
        private readonly array $timeframes = ['5m', '15m', '1h'],
    ) {}

    /**
     * Execute the job.
     *
     * Loops every coin × timeframe combination and persists an AI decision for each.
     * Errors for individual coins are caught, logged, and skipped so remaining coins
     * are always processed.
     */
    public function handle(AiAdvisorService $advisorService): void
    {
        $coins = $this->resolveCoins();

        foreach ($coins as $coin) {
            foreach ($this->timeframes as $timeframe) {
                try {
                    $this->processOne($advisorService, $coin, $timeframe);
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
     * Run one coin/timeframe cycle: call the advisor service and persist the result.
     */
    private function processOne(AiAdvisorService $advisorService, string $coin, string $timeframe): void
    {
        $result = $advisorService->advise($coin, $timeframe);

        if ($result === null) {
            Log::info('[RunAiDecisionJob] No indicator data, skipping persistence', [
                'coin' => $coin,
                'timeframe' => $timeframe,
            ]);

            return;
        }

        $indicator = $result['indicator'];
        $decision = $result['decision'];

        $startedAt = microtime(true);

        AiDecision::create([
            'coin' => $coin,
            'timeframe' => $timeframe,
            'timestamp' => $indicator->timestamp,
            'input_data' => [
                'price' => $indicator->price,
                'rsi' => $indicator->rsi,
                'ema9' => $indicator->ema9,
                'ema21' => $indicator->ema21,
                'volume' => $indicator->volume,
                'volume_ma' => $indicator->volume_ma,
                'trend' => $indicator->trend,
                'timeframe' => $timeframe,
            ],
            'action' => $decision['action'],
            'confidence' => $decision['confidence'],
            'risk_level' => $decision['risk_level'],
            'reason' => $decision['reason'],
            'price_at_decision' => $indicator->price,
            'raw_response' => $result['raw_response'],
            'model_used' => $result['model_used'],
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
        ]);

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
        if (! empty($this->coins)) {
            return array_values(array_unique($this->coins));
        }

        /** @var array<string> $configured */
        $configured = config('market.coins', []);

        return array_values(array_unique($configured));
    }
}
