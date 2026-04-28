<?php

namespace App\Services\Trading;

use App\Models\AiDecision;
use App\Models\MarketContext;
use App\Models\MarketIndicator;
use App\Services\Trading\DTO\SignalViewDTO;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
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
     * Build a base summary-scope query for model-ops context analytics.
     */
    public function modelOpsContextQuery(?CarbonInterface $from = null, ?CarbonInterface $to = null): Builder
    {
        [$resolvedFrom, $resolvedTo] = $this->resolveRange($from, $to);

        $query = MarketContext::query()
            ->where('timeframe', 'summary')
            ->whereIn('source', ['mtf', 'mtf_service']);

        if ($resolvedFrom !== null && $resolvedTo !== null) {
            $query->whereBetween('timestamp', [$resolvedFrom, $resolvedTo]);
        }

        return $query;
    }

    /**
     * Build decision authority chain query for tabular inspection.
     */
    public function decisionAuthorityChainQuery(?CarbonInterface $from = null, ?CarbonInterface $to = null): Builder
    {
        return $this->modelOpsContextQuery($from, $to)
            ->select([
                'id',
                'execution_id',
                'coin',
                'timestamp',
                'mcp_passed',
                'mcp_score',
                'mcp_candidate',
                'preliminary_action',
                'fusion_ai_action',
                'fusion_final_action',
                'final_action',
                'final_confidence',
                'decision_status',
            ])
            ->orderByDesc('timestamp');
    }

    /**
     * Build execution traceability query keyed by execution_id.
     */
    public function executionTraceabilityQuery(?CarbonInterface $from = null, ?CarbonInterface $to = null): Builder
    {
        [$resolvedFrom, $resolvedTo] = $this->resolveRange($from, $to);

        $query = AiDecision::query()
            ->from('ai_decisions as d')
            ->whereNotNull('d.execution_id')
            ->selectRaw('DISTINCT ON (d.execution_id) d.id, d.execution_id')
            ->selectRaw('d.timestamp as executed_at')
            ->selectRaw('(SELECT COUNT(*) FROM ai_decisions a WHERE a.execution_id = d.execution_id) as signal_count')
            ->selectRaw("(SELECT COUNT(*) FROM ai_decisions a WHERE a.execution_id = d.execution_id AND a.action IN ('BUY', 'SELL')) as actionable_count")
            ->selectRaw('(SELECT COUNT(DISTINCT a.model) FROM ai_decisions a WHERE a.execution_id = d.execution_id) as model_count')
            ->orderBy('d.execution_id')
            ->orderByDesc('d.timestamp');

        if ($resolvedFrom !== null && $resolvedTo !== null) {
            $query->whereBetween('d.timestamp', [$resolvedFrom, $resolvedTo]);
        }

        return AiDecision::query()
            ->fromSub($query, 'ai_decisions')
            ->orderByDesc('executed_at');
    }

    /**
     * Aggregate MCP gate health metrics.
     *
     * @return array{pass_rate: float, passed: int, failed: int, average_score: float|null, dominant_candidate: string}
     */
    public function mcpGateHealth(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $baseQuery = $this->modelOpsContextQuery($from, $to);

        $total = (clone $baseQuery)->whereNotNull('mcp_passed')->count();
        $passed = (clone $baseQuery)->where('mcp_passed', true)->count();
        $failed = (clone $baseQuery)->where('mcp_passed', false)->count();
        $averageScore = (clone $baseQuery)->whereNotNull('mcp_score')->avg('mcp_score');

        $dominantCandidateRow = (clone $baseQuery)
            ->selectRaw("COALESCE(NULLIF(TRIM(mcp_candidate), ''), 'NONE') as candidate")
            ->selectRaw('COUNT(*) as candidate_count')
            ->groupBy('candidate')
            ->orderByDesc('candidate_count')
            ->first();

        return [
            'pass_rate' => $total > 0 ? round(($passed / $total) * 100, 1) : 0.0,
            'passed' => $passed,
            'failed' => $failed,
            'average_score' => $averageScore !== null ? round((float) $averageScore, 1) : null,
            'dominant_candidate' => strtoupper((string) ($dominantCandidateRow?->candidate ?? 'NONE')),
        ];
    }

    /**
     * Aggregate per-model performance scorecards.
     *
     * @return array<int, array{model: string, total: int, actionable: int, avg_confidence: float, ai_usage_rate: float}>
     */
    public function perModelScorecard(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        [$resolvedFrom, $resolvedTo] = $this->resolveRange($from, $to);

        $query = AiDecision::query()
            ->whereNotNull('model');

        if ($resolvedFrom !== null && $resolvedTo !== null) {
            $query->whereBetween('timestamp', [$resolvedFrom, $resolvedTo]);
        }

        $rows = $query
            ->selectRaw('model')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN action IN ('BUY', 'SELL') THEN 1 ELSE 0 END) as actionable")
            ->selectRaw('AVG(confidence) as avg_confidence')
            ->selectRaw('AVG(CASE WHEN ai_used = true THEN 1 ELSE 0 END) as ai_usage')
            ->groupBy('model')
            ->get();

        $order = [
            'counter_trend' => 1,
            'pre_pump' => 2,
            'momentum' => 3,
        ];

        return $rows
            ->sortBy(fn ($row): int => Arr::get($order, (string) $row->model, 999))
            ->map(fn ($row): array => [
                'model' => (string) $row->model,
                'total' => (int) $row->total,
                'actionable' => (int) $row->actionable,
                'avg_confidence' => round((float) ($row->avg_confidence ?? 0), 1),
                'ai_usage_rate' => round(((float) ($row->ai_usage ?? 0)) * 100, 1),
            ])
            ->values()
            ->all();
    }

    /**
     * Aggregate notification eligibility metrics based on accepted BUY/SELL
     * chain outcomes with duplicate-signal suppression semantics.
     *
     * @return array{eligible_unique_signals: int, duplicate_groups: int, accepted_actionable_rows: int, rejected_rows: int}
     */
    public function notificationEligibility(?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $baseQuery = $this->modelOpsContextQuery($from, $to);

        $acceptedActionable = (clone $baseQuery)
            ->where('decision_status', 'accepted')
            ->whereIn('final_action', ['BUY', 'SELL'])
            ->get(['coin', 'timeframe', 'timestamp', 'final_action']);

        $groups = $acceptedActionable->groupBy(function (MarketContext $row): string {
            $timestamp = $row->timestamp?->toIso8601String() ?? 'na';

            return implode('|', [
                (string) $row->coin,
                (string) $row->timeframe,
                $timestamp,
                (string) ($row->final_action ?? 'NA'),
            ]);
        });

        $eligibleUniqueSignals = $groups->filter(fn (Collection $rows): bool => $rows->count() === 1)->count();
        $duplicateGroups = $groups->filter(fn (Collection $rows): bool => $rows->count() > 1)->count();

        $rejectedRows = (clone $baseQuery)
            ->where('decision_status', 'rejected')
            ->count();

        return [
            'eligible_unique_signals' => $eligibleUniqueSignals,
            'duplicate_groups' => $duplicateGroups,
            'accepted_actionable_rows' => $acceptedActionable->count(),
            'rejected_rows' => $rejectedRows,
        ];
    }

    /**
     * Return one SignalViewDTO per coin, using the most recent ai_decision row.
     *
     * @param  array<string>|null  $coins  Filter to specific coins; null = all.
     * @return Collection<int, SignalViewDTO>
     */
    public function latestSignalsPerCoin(?array $coins = null): Collection
    {
        $query = AiDecision::query()
            ->when($coins !== null, fn ($q) => $q->whereIn('coin', $coins))
            ->orderByDesc('id');

        /** @var Collection<string, AiDecision> $latestDecisions */
        $latestDecisions = $query
            ->get()
            ->unique('coin');

        return $latestDecisions->map(fn (AiDecision $decision) => $this->buildDto($decision));
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
            ->when($coins !== null, fn ($q) => $q->whereIn('coin', $coins))
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

    /**
     * @return array{0: CarbonInterface|null, 1: CarbonInterface|null}
     */
    private function resolveRange(?CarbonInterface $from, ?CarbonInterface $to): array
    {
        if ($from !== null && $to !== null) {
            return [$from, $to];
        }

        $latestRaw = MarketContext::query()->max('timestamp');

        if ($latestRaw === null) {
            return [null, null];
        }

        $latest = $latestRaw instanceof CarbonInterface
            ? $latestRaw
            : Carbon::parse((string) $latestRaw);

        return [$latest->copy()->subMinutes(60), $latest];
    }
}
