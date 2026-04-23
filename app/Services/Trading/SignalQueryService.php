<?php

namespace App\Services\Trading;

use App\Models\AiDecision;
use App\Models\MarketContext;
use App\Models\MarketIndicator;
use App\Services\Trading\DTO\SignalViewDTO;
use Illuminate\Support\Collection;

/**
 * SignalQueryService
 *
 * Aggregates the latest AI decisions, market indicators, and MTF context per
 * coin for dashboard display.  All queries are read-only; no side-effects.
 */
class SignalQueryService
{
    /**
     * Return one SignalViewDTO per coin, using the most recent ai_decision row.
     *
     * @param  array<string>|null  $coins  Filter to specific coins; null = all.
     * @return Collection<int, SignalViewDTO>
     */
    public function latestSignalsPerCoin(?array $coins = null): Collection
    {
        $query = AiDecision::query()
            ->when($coins !== null, fn($q) => $q->whereIn('coin', $coins))
            ->orderByDesc('id');

        /** @var Collection<string, AiDecision> $latestDecisions */
        $latestDecisions = $query
            ->get()
            ->unique('coin');

        return $latestDecisions->map(fn(AiDecision $decision) => $this->buildDto($decision));
    }

    /**
     * Build a SignalViewDTO from a single AiDecision row.
     */
    public function buildDto(AiDecision $decision): SignalViewDTO
    {
        $indicator = MarketIndicator::query()
            ->where('coin', $decision->coin)
            ->where('timeframe', $decision->timeframe)
            ->orderByDesc('timestamp')
            ->first();

        $context = MarketContext::query()
            ->where('coin', $decision->coin)
            ->whereIn('source', ['mtf', 'mtf_service'])
            ->orderByDesc('timestamp')
            ->first();

        $trend = $this->deriveTrend($indicator?->ema9, $indicator?->ema21);

        return new SignalViewDTO(
            coin: $decision->coin,
            priceAtDecision: $decision->price_at_decision !== null ? (float) $decision->price_at_decision : null,
            rsi: $indicator !== null ? (float) $indicator->rsi : null,
            trend: $trend,
            action: $decision->action,
            confidence: (int) $decision->confidence,
            riskLevel: (string) $decision->risk_level,
            result: $decision->result,
            mtfMode: $context?->market_regime,
            mtfAlignment: $context?->sentiment,
            priceAfter5m: $decision->price_after_5m !== null ? (float) $decision->price_after_5m : null,
            priceAfter15m: $decision->price_after_15m !== null ? (float) $decision->price_after_15m : null,
            maxProfit: $decision->max_profit !== null ? (float) $decision->max_profit : null,
            maxDrawdown: $decision->max_drawdown !== null ? (float) $decision->max_drawdown : null,
            createdAt: $decision->timestamp,
        );
    }

    /**
     * Compute performance stats across all (or filtered) ai_decisions.
     *
     * @param  array<string>|null  $coins
     * @return array{win_rate: float, avg_profit: float, avg_drawdown: float, total: int, wins: int}
     */
    public function performanceStats(?array $coins = null): array
    {
        $decisions = AiDecision::query()
            ->when($coins !== null, fn($q) => $q->whereIn('coin', $coins))
            ->whereNotNull('result')
            ->get(['result', 'max_profit', 'max_drawdown']);

        $total = $decisions->count();

        if ($total === 0) {
            return ['win_rate' => 0.0, 'avg_profit' => 0.0, 'avg_drawdown' => 0.0, 'total' => 0, 'wins' => 0];
        }

        $wins = $decisions->where('result', 'win')->count();
        $avgProfit = (float) $decisions->avg('max_profit');
        $avgDrawdown = (float) $decisions->avg('max_drawdown');

        return [
            'win_rate' => round(($wins / $total) * 100, 2),
            'avg_profit' => round($avgProfit, 4),
            'avg_drawdown' => round($avgDrawdown, 4),
            'total' => $total,
            'wins' => $wins,
        ];
    }

    /**
     * Derive trend label from EMA values.
     */
    private function deriveTrend(mixed $ema9, mixed $ema21): string
    {
        if ($ema9 === null || $ema21 === null) {
            return 'NEUTRAL';
        }

        return (float) $ema9 > (float) $ema21 ? 'UP' : 'DOWN';
    }
}
