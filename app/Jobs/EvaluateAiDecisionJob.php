<?php

namespace App\Jobs;

use App\Models\AiDecision;
use App\Models\MarketIndicator;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * EvaluateAiDecisionJob
 *
 * Delayed evaluator that enriches a persisted AI decision with post-signal
 * outcome metrics from market_indicators (5m/15m prices, result,
 * max profit, and max drawdown).
 */
class EvaluateAiDecisionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @var array<int>
     */
    public array $backoff = [60, 120];

    /**
     * Create a new job instance.
     *
     * Input:
     * - aiDecisionId: primary key from ai_decisions.
     */
    public function __construct(private readonly int $aiDecisionId) {}

    /**
     * Execute the job.
     *
     * Output:
     * - Updates ai_decisions evaluation fields for the given decision.
     */
    public function handle(): void
    {
        $decision = AiDecision::query()->find($this->aiDecisionId);

        if ($decision === null) {
            Log::warning('[EvaluateAiDecisionJob] ai_decision not found', [
                'ai_decision_id' => $this->aiDecisionId,
            ]);

            return;
        }

        $decisionTime = CarbonImmutable::parse($decision->created_at);
        $entryPrice = (float) $decision->price_at_decision;

        $priceAfter5m = $this->resolveFuturePrice($decision->coin, $decision->timeframe, $decisionTime->addMinutes(5));
        $priceAfter15m = $this->resolveFuturePrice($decision->coin, $decision->timeframe, $decisionTime->addMinutes(15));

        $performance = $this->computePerformanceWindow(
            coin: $decision->coin,
            timeframe: $decision->timeframe,
            from: $decisionTime,
            to: $decisionTime->addMinutes(15),
            entryPrice: $entryPrice,
            action: (string) $decision->action,
        );

        $profit = $this->computeProfit((string) $decision->action, $entryPrice, $priceAfter15m);

        $decision->update([
            'price_after_5m' => $priceAfter5m,
            'price_after_15m' => $priceAfter15m,
            'result' => $this->classifyResult($profit),
            'max_profit' => $performance['max_profit'],
            'max_drawdown' => $performance['max_drawdown'],
        ]);

        Log::info('[EvaluateAiDecisionJob] ai_decision evaluated', [
            'ai_decision_id' => $decision->id,
            'coin' => $decision->coin,
            'timeframe' => $decision->timeframe,
            'price_after_5m' => $priceAfter5m,
            'price_after_15m' => $priceAfter15m,
            'result' => $this->classifyResult($profit),
            'max_profit' => $performance['max_profit'],
            'max_drawdown' => $performance['max_drawdown'],
        ]);
    }

    private function resolveFuturePrice(string $coin, string $timeframe, CarbonImmutable $targetTime): ?float
    {
        $indicator = MarketIndicator::query()
            ->where('coin', $coin)
            ->where('timeframe', $timeframe)
            ->where('timestamp', '>=', $targetTime)
            ->orderBy('timestamp')
            ->first();

        if ($indicator === null) {
            return null;
        }

        return (float) $indicator->price;
    }

    /**
     * @return array{max_profit: ?float, max_drawdown: ?float}
     */
    private function computePerformanceWindow(
        string $coin,
        string $timeframe,
        CarbonImmutable $from,
        CarbonImmutable $to,
        float $entryPrice,
        string $action,
    ): array {
        $prices = MarketIndicator::query()
            ->where('coin', $coin)
            ->where('timeframe', $timeframe)
            ->whereBetween('timestamp', [$from, $to])
            ->orderBy('timestamp')
            ->pluck('price');

        if ($prices->isEmpty()) {
            return [
                'max_profit' => null,
                'max_drawdown' => null,
            ];
        }

        $maxPrice = (float) $prices->max();
        $minPrice = (float) $prices->min();

        if ($action === 'BUY') {
            return [
                'max_profit' => $maxPrice - $entryPrice,
                'max_drawdown' => $minPrice - $entryPrice,
            ];
        }

        if ($action === 'SELL') {
            return [
                'max_profit' => $entryPrice - $minPrice,
                'max_drawdown' => $entryPrice - $maxPrice,
            ];
        }

        return [
            'max_profit' => 0.0,
            'max_drawdown' => 0.0,
        ];
    }

    private function computeProfit(string $action, float $entryPrice, ?float $priceAfter15m): float
    {
        if ($priceAfter15m === null) {
            return 0.0;
        }

        if ($action === 'BUY') {
            return $priceAfter15m - $entryPrice;
        }

        if ($action === 'SELL') {
            return $entryPrice - $priceAfter15m;
        }

        return 0.0;
    }

    private function classifyResult(float $profit): string
    {
        if ($profit > 0) {
            return 'win';
        }

        if ($profit < 0) {
            return 'loss';
        }

        return 'neutral';
    }
}
