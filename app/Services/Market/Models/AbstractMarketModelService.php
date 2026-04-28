<?php

namespace App\Services\Market\Models;

use App\Models\GeneralConfig;
use App\Models\MarketIndicator;
use App\Services\Market\CoinUniverseService;
use App\Services\Market\MarketRegimeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

abstract class AbstractMarketModelService
{
    public function __construct(
        private readonly CoinUniverseService $coinUniverseService,
        private readonly MarketRegimeService $marketRegimeService,
    ) {}

    /**
     * Evaluate the latest candidate universe and return the top-ranked signals.
     *
     * @return Collection<int, ModelSignalDTO>
     */
    public function evaluateUniverse(string $executionId = ''): Collection
    {
        $signals = $this->resolveCandidateCoins()
            ->map(fn(string $coin): ?ModelSignalDTO => $this->evaluateCoin($coin))
            ->filter(fn(?ModelSignalDTO $signal): bool => $signal !== null)
            ->values();

        $rankedSignals = $this->rankTopCoins($signals);

        $this->cacheSignals($rankedSignals, $executionId);

        Log::info(sprintf('[%s] Model evaluation completed', static::class), [
            'execution_id' => $executionId,
            'model' => $this->modelKey(),
            'candidate_count' => $signals->count(),
            'ranked_count' => $rankedSignals->count(),
        ]);

        return $rankedSignals;
    }

    /**
     * Evaluate one coin and return a signal when the setup passes model thresholds.
     *
     * @param  array<string, array<string, mixed>|null>|null  $indicators
     */
    abstract public function evaluateCoin(string $coin, ?array $indicators = null): ?ModelSignalDTO;

    /**
     * Rank the top model signals.
     *
     * @param  Collection<int, ModelSignalDTO>  $signals
     * @return Collection<int, ModelSignalDTO>
     */
    abstract public function rankTopCoins(Collection $signals): Collection;

    /**
     * @return array<int, string>
     */
    abstract protected function requiredTimeframes(): array;

    abstract protected function modelKey(): string;

    /**
     * @return array<string, mixed>
     */
    protected function resolveMarketRegime(): array
    {
        return $this->marketRegimeService->getLatestRegime();
    }

    /**
     * @return Collection<int, string>
     */
    protected function resolveCandidateCoins(): Collection
    {
        $universe = collect($this->coinUniverseService->getCachedUniverse())
            ->pluck('coin')
            ->filter(fn(mixed $coin): bool => is_string($coin) && $coin !== '');

        if ($universe->isEmpty()) {
            $universe = collect(GeneralConfig::getCoins());
        }

        return $universe
            ->map(fn(mixed $coin): string => (string) $coin)
            ->filter(fn(string $coin): bool => $coin !== '')
            ->unique()
            ->values();
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    protected function resolveIndicators(string $coin): array
    {
        $resolved = [];

        foreach ($this->requiredTimeframes() as $timeframe) {
            $resolved[$timeframe] = $this->fetchLatestIndicator($coin, $timeframe);
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function fetchLatestIndicator(string $coin, string $timeframe): ?array
    {
        $indicator = MarketIndicator::query()
            ->where('coin', $coin)
            ->where('timeframe', $timeframe)
            ->orderByDesc('timestamp')
            ->first();

        if ($indicator === null) {
            return null;
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $indicator->getAttributes();
        $volume = is_numeric($attributes['volume'] ?? null) ? (float) $attributes['volume'] : null;
        $volumeMa = is_numeric($attributes['volume_ma'] ?? null) ? (float) $attributes['volume_ma'] : null;

        return [
            'timeframe' => (string) $indicator->timeframe,
            'price' => is_numeric($attributes['price'] ?? null) ? (float) $attributes['price'] : null,
            'rsi' => is_numeric($attributes['rsi'] ?? null) ? (float) $attributes['rsi'] : null,
            'ema9' => is_numeric($attributes['ema9'] ?? null) ? (float) $attributes['ema9'] : null,
            'ema21' => is_numeric($attributes['ema21'] ?? null) ? (float) $attributes['ema21'] : null,
            'trend' => (string) ($attributes['trend'] ?? 'sideways'),
            'volatility' => is_numeric($attributes['volatility'] ?? null) ? (float) $attributes['volatility'] : null,
            'volume_ratio' => $volume !== null && $volumeMa !== null && $volumeMa > 0.0
                ? round($volume / $volumeMa, 4)
                : null,
            'timestamp' => $indicator->timestamp?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, float>  $components
     * @param  array<string, float>  $weights
     */
    protected function calculateWeightedScore(array $components, array $weights): int
    {
        $score = 0.0;

        foreach ($weights as $component => $weight) {
            $score += $this->clampUnit($components[$component] ?? 0.0) * $weight;
        }

        return (int) round($this->clampUnit($score) * 100);
    }

    protected function clampInt(int $value, int $min = 0, int $max = 100): int
    {
        return max($min, min($max, $value));
    }

    protected function clampUnit(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }

    protected function bullishTrend(?string $trend): bool
    {
        return $trend === 'uptrend';
    }

    protected function bearishTrend(?string $trend): bool
    {
        return $trend === 'downtrend';
    }

    /**
     * @param  Collection<int, ModelSignalDTO>  $signals
     */
    protected function cacheSignals(Collection $signals, string $executionId): void
    {
        Cache::put(
            sprintf('trading_models:%s:latest', $this->modelKey()),
            [
                'execution_id' => $executionId,
                'market_regime' => $this->resolveMarketRegime(),
                'signals' => $signals->map(fn(ModelSignalDTO $signal): array => $signal->toArray())->all(),
            ],
            now()->addHour(),
        );
    }
}
