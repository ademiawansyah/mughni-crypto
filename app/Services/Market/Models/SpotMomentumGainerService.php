<?php

namespace App\Services\Market\Models;

use App\Models\Coin;
use App\Services\External\CoinGeckoService;
use App\Services\External\CoinMarketCapService;
use App\Services\Market\MarketRegimeService;
use App\Services\Notification\NotificationService;
use App\Services\Trading\ModelOutputStoreService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SpotMomentumGainerService
{
    private const MODEL_NAME = 'spot_momentum_gainer';

    private const MODEL_VERSION = '2.0';

    private const MAX_RESULTS = 10;

    private const SOURCE_LIMIT = 200;

    private const CANDLE_LIMIT = 7;

    private const MIN_MARKET_CAP = 100_000_000;

    /** @var array<int, string> */
    private const STABLECOINS = [
        'usdt',
        'usdc',
        'dai',
        'busd',
        'tusd',
        'frax',
        'usdd',
        'usdp',
        'gusd',
        'lusd',
    ];

    /** @var array<int, string> */
    private const WRAPPED_KEYWORDS = ['wrapped', 'wbtc', 'weth', 'steth', 'reth', 'cbeth'];

    public function __construct(
        private readonly CoinMarketCapService $coinMarketCapService,
        private readonly CoinGeckoService $coinGeckoService,
        private readonly MarketRegimeService $marketRegimeService,
        private readonly ModelOutputStoreService $modelOutputStoreService,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Execute Model 4 (Spot Momentum Gainer) scanning pipeline.
     *
     * @return array{
     *   execution_id: string,
     *   model: string,
     *   version: string,
     *   timestamp: string,
     *   execution_date: string,
     *   evaluated: int,
     *   shortlisted: int,
     *   results: array<int, array<string, mixed>>,
     * }
     */
    public function execute(?string $executionId = null): array
    {
        $resolvedExecutionId = $executionId ?: Str::uuid()->toString();
        $minimumScore = (float) config('models.spot_momentum_gainer.min_score', 60);

        Log::info('[SpotMomentumGainerService] Started execution', [
            'execution_id' => $resolvedExecutionId,
            'minimum_score' => $minimumScore,
        ]);

        [$candidates, $sourceUsed] = $this->resolveTopCandidates($resolvedExecutionId);

        $signals = [];
        $failedCoins = [];

        foreach ($candidates as $candidate) {
            $coin = $candidate['coin'];
            $marketData = $candidate['market_data'];

            $dailyKlines = $this->marketRegimeService->getOhlcvDataForCoin(
                $coin->symbol,
                '1d',
                self::CANDLE_LIMIT,
            );

            if ($dailyKlines === []) {
                $failedCoins[] = [
                    'id' => $coin->id,
                    'symbol' => strtoupper((string) $coin->symbol),
                    'reason' => 'missing_ohlcv',
                    'context' => [
                        'timeframe' => '1d',
                        'required' => self::CANDLE_LIMIT,
                    ],
                    'score' => 0,
                    'price' => (float) ($coin->current_price ?? 0),
                ];

                continue;
            }

            $analysis = $this->analyzeCandidate(
                coin: $coin,
                marketData: $marketData,
                dailyKlines: $dailyKlines,
                minimumScore: $minimumScore,
                sourceUsed: $sourceUsed,
            );

            if ($analysis['signal'] === null) {
                $failedCoins[] = [
                    'id' => $coin->id,
                    'symbol' => strtoupper((string) $coin->symbol),
                    'reason' => $analysis['rejection_reason'],
                    'context' => $analysis['rejection_context'],
                    'score' => $analysis['score'],
                    'price' => (float) ($coin->current_price ?? 0),
                ];

                continue;
            }

            $signals[] = $analysis['signal'];
        }

        usort(
            $signals,
            static fn(array $left, array $right): int => $right['total_score'] <=> $left['total_score'],
        );

        $limitedSignals = array_slice($signals, 0, self::MAX_RESULTS);
        $ranked = array_values(array_map(
            static fn(array $signal, int $index): array => array_merge($signal, ['rank' => $index + 1]),
            $limitedSignals,
            array_keys($limitedSignals),
        ));

        $timestamp = now()->toIso8601String();
        $executionDate = now()->toDateString();

        $standardOutput = [
            'model' => self::MODEL_NAME,
            'version' => self::MODEL_VERSION,
            'timestamp' => $timestamp,
            'execution_date' => $executionDate,
            'results' => $ranked,
        ];

        $supportingData = [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => count($candidates),
            'shortlisted' => count($ranked),
            'failed_count' => count($failedCoins),
            'minimum_score' => $minimumScore,
            'source_used' => $sourceUsed,
            'all_scored_results' => $signals,
            'failed_coins' => $failedCoins,
        ];

        $this->modelOutputStoreService->store(
            modelName: self::MODEL_NAME,
            executionId: $resolvedExecutionId,
            executionDate: $executionDate,
            result: $standardOutput,
            supportingData: $supportingData,
        );

        $this->dispatchDailyNotification($resolvedExecutionId, $ranked, $supportingData['evaluated']);

        $result = array_merge($standardOutput, [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => $supportingData['evaluated'],
            'shortlisted' => $supportingData['shortlisted'],
        ]);

        Log::info('[SpotMomentumGainerService] Completed execution', [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => $result['evaluated'],
            'shortlisted' => $result['shortlisted'],
            'source_used' => $sourceUsed,
        ]);

        return $result;
    }

    /**
     * @return array{0: array<int, array{coin: Coin, market_data: array<string, mixed>}>, 1: string}
     */
    private function resolveTopCandidates(string $executionId): array
    {
        $cmcCoins = $this->coinMarketCapService->fetchListingsLatest(self::SOURCE_LIMIT);

        if ($cmcCoins !== []) {
            $candidates = $this->buildCandidatesFromMarketData($cmcCoins);

            Log::info('[SpotMomentumGainerService] Candidates resolved from CoinMarketCap', [
                'execution_id' => $executionId,
                'count' => count($candidates),
            ]);

            return [$candidates, 'coinmarketcap'];
        }

        $coinGeckoCoins = $this->coinGeckoService->fetchCoinMarkets(page: 1, perPage: self::SOURCE_LIMIT);
        $fallbackPayload = array_map(static function (array $coin): array {
            return [
                'symbol' => strtoupper((string) ($coin['symbol'] ?? '')),
                'name' => (string) ($coin['name'] ?? ''),
                'market_cap' => (float) ($coin['market_cap'] ?? 0),
                'volume_24h' => (float) (($coin['total_volume'] ?? 0)),
                'price' => (float) ($coin['current_price'] ?? 0),
                'percent_change_24h' => (float) ($coin['price_change_percentage_24h'] ?? 0),
            ];
        }, $coinGeckoCoins);

        $candidates = $this->buildCandidatesFromMarketData($fallbackPayload);

        Log::warning('[SpotMomentumGainerService] CoinMarketCap unavailable, using CoinGecko fallback', [
            'execution_id' => $executionId,
            'count' => count($candidates),
        ]);

        return [$candidates, 'coingecko_fallback'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $marketCoins
     * @return array<int, array{coin: Coin, market_data: array<string, mixed>}>
     */
    private function buildCandidatesFromMarketData(array $marketCoins): array
    {
        $filtered = array_values(array_filter($marketCoins, function (array $coin): bool {
            $symbol = strtolower((string) ($coin['symbol'] ?? ''));
            $name = strtolower((string) ($coin['name'] ?? ''));

            if ($symbol === '' || in_array($symbol, self::STABLECOINS, true)) {
                return false;
            }

            foreach (self::WRAPPED_KEYWORDS as $keyword) {
                if (str_contains($name, $keyword)) {
                    return false;
                }
            }

            return (float) ($coin['market_cap'] ?? 0) >= self::MIN_MARKET_CAP;
        }));

        usort(
            $filtered,
            static fn(array $left, array $right): int => ((float) ($right['percent_change_24h'] ?? 0) <=> (float) ($left['percent_change_24h'] ?? 0)),
        );

        $topTen = array_slice($filtered, 0, self::MAX_RESULTS);
        $symbols = array_values(array_unique(array_map(
            static fn(array $coin): string => strtolower((string) ($coin['symbol'] ?? '')),
            $topTen,
        )));

        /** @var Collection<int, Coin> $coins */
        $coins = Coin::query()
            ->whereIn('symbol', $symbols)
            ->get()
            ->keyBy(static fn(Coin $coin): string => strtolower((string) $coin->symbol));

        $candidates = [];

        foreach ($topTen as $coinData) {
            $symbol = strtolower((string) ($coinData['symbol'] ?? ''));
            $coin = $coins->get($symbol);

            if (! $coin instanceof Coin) {
                continue;
            }

            $candidates[] = [
                'coin' => $coin,
                'market_data' => $coinData,
            ];
        }

        return $candidates;
    }

    /**
     * @param  array<int, array<int, mixed>>  $dailyKlines
     * @return array{
     *   signal: array<string, mixed>|null,
     *   rejection_reason: string|null,
     *   rejection_context: array<string, mixed>,
     *   score: float
     * }
     */
    private function analyzeCandidate(
        Coin $coin,
        array $marketData,
        array $dailyKlines,
        float $minimumScore,
        string $sourceUsed,
    ): array {
        $candles = $this->mapKlinesToCandles($dailyKlines);

        if (count($candles) < self::CANDLE_LIMIT) {
            return [
                'signal' => null,
                'rejection_reason' => 'insufficient_daily_candles',
                'rejection_context' => [
                    'required' => self::CANDLE_LIMIT,
                    'actual' => count($candles),
                ],
                'score' => 0,
            ];
        }

        $today = $candles[count($candles) - 1];
        $yesterday = $candles[count($candles) - 2];

        $body = $today['close'] - $today['open'];
        $range = max($today['high'] - $today['low'], 0.00000001);
        $bodyRatio = $body > 0 ? $body / $range : 0;
        $upperWickRatio = $body > 0 ? (($today['high'] - $today['close']) / $body) : INF;

        $previousFiveVolumes = array_slice(array_column($candles, 'volume'), -6, 5);
        $avgVolume = $previousFiveVolumes !== []
            ? (array_sum($previousFiveVolumes) / count($previousFiveVolumes))
            : 0;

        $isGreenCandle = $today['close'] > $today['open'];
        $hasLargeBody = $bodyRatio >= 0.6;
        $hasMinimalUpperWick = $upperWickRatio <= 0.2;
        $isBreakoutClose = $today['close'] > $yesterday['high'];
        $isHighVolume = $today['volume'] > $avgVolume && $avgVolume > 0;

        if (! ($isGreenCandle && $hasLargeBody && $hasMinimalUpperWick && $isBreakoutClose && $isHighVolume)) {
            return [
                'signal' => null,
                'rejection_reason' => 'bullish_gate_failed',
                'rejection_context' => [
                    'green_candle' => $isGreenCandle,
                    'large_body' => $hasLargeBody,
                    'minimal_upper_wick' => $hasMinimalUpperWick,
                    'close_above_prior_high' => $isBreakoutClose,
                    'high_volume' => $isHighVolume,
                    'body_ratio' => round($bodyRatio, 4),
                    'upper_wick_ratio' => is_infinite($upperWickRatio) ? null : round($upperWickRatio, 4),
                    'volume_today' => $today['volume'],
                    'avg_volume_5' => $avgVolume,
                ],
                'score' => 0,
            ];
        }

        $volumeRatio = $avgVolume > 0 ? ($today['volume'] / $avgVolume) : 0;
        $change24h = (float) ($marketData['percent_change_24h'] ?? 0);

        $changeScore = $this->normalizeChange24hScore($change24h);
        $volumeScore = $this->normalizeVolumeRatioScore($volumeRatio);
        $bodyScore = $this->normalizeBodyRatioScore($bodyRatio);

        $weights = [
            'change_24h' => (float) config('models.spot_momentum_gainer.scoring.change_24h', 0.40),
            'volume_ratio' => (float) config('models.spot_momentum_gainer.scoring.volume_ratio', 0.35),
            'body_ratio' => (float) config('models.spot_momentum_gainer.scoring.body_ratio', 0.25),
        ];

        $totalScore =
            ($changeScore * $weights['change_24h']) +
            ($volumeScore * $weights['volume_ratio']) +
            ($bodyScore * $weights['body_ratio']);

        if ($totalScore < $minimumScore) {
            return [
                'signal' => null,
                'rejection_reason' => 'below_minimum_score',
                'rejection_context' => [
                    'minimum' => $minimumScore,
                    'actual' => round($totalScore, 2),
                ],
                'score' => round($totalScore, 2),
            ];
        }

        $price = (float) ($today['close'] ?: ($coin->current_price ?? 0));

        return [
            'signal' => [
                'symbol' => strtoupper((string) $coin->symbol) . 'USDT',
                'price' => round($price, 8),
                'total_score' => round($totalScore, 2),
                'components' => [
                    'change_24h' => round($changeScore, 2),
                    'volume_ratio' => round($volumeScore, 2),
                    'body_ratio' => round($bodyScore, 2),
                    'gate' => [
                        'green_candle' => true,
                        'large_body' => true,
                        'minimal_upper_wick' => true,
                        'close_above_prior_high' => true,
                        'high_volume' => true,
                    ],
                    'raw' => [
                        'change_24h' => round($change24h, 4),
                        'volume_ratio' => round($volumeRatio, 4),
                        'body_ratio' => round($bodyRatio, 4),
                    ],
                ],
                'metadata' => [
                    'entry_point' => round($price, 8),
                    'stop_loss' => round((float) $today['low'], 8),
                    'entry_timeframe' => '1D',
                    'strategy' => self::MODEL_NAME,
                    'source_used' => $sourceUsed,
                    'spot_only' => true,
                    'allow_short' => false,
                    'allow_leverage' => false,
                ],
            ],
            'rejection_reason' => null,
            'rejection_context' => [],
            'score' => round($totalScore, 2),
        ];
    }

    private function normalizeChange24hScore(float $change24h): float
    {
        $bounded = max(0.0, min($change24h, 25.0));

        return ($bounded / 25.0) * 100;
    }

    private function normalizeVolumeRatioScore(float $volumeRatio): float
    {
        $bounded = max(0.0, min($volumeRatio, 3.0));

        return ($bounded / 3.0) * 100;
    }

    private function normalizeBodyRatioScore(float $bodyRatio): float
    {
        $bounded = max(0.0, min($bodyRatio, 1.0));

        return $bounded * 100;
    }

    /**
     * @param  array<int, array<int, mixed>>  $klines
     * @return array<int, array{open: float, high: float, low: float, close: float, volume: float}>
     */
    private function mapKlinesToCandles(array $klines): array
    {
        return array_values(array_map(static function (array $kline): array {
            return [
                'open' => (float) ($kline[1] ?? 0),
                'high' => (float) ($kline[2] ?? 0),
                'low' => (float) ($kline[3] ?? 0),
                'close' => (float) ($kline[4] ?? 0),
                'volume' => (float) ($kline[5] ?? 0),
            ];
        }, $klines));
    }

    /**
     * @param  array<int, array<string, mixed>>  $rankedSignals
     */
    private function dispatchDailyNotification(string $executionId, array $rankedSignals, int $evaluated): void
    {
        if ($rankedSignals === []) {
            $this->notificationService->sendSystemMessage([
                'execution_id' => $executionId,
                'title' => 'Spot Momentum Gainer - No Setup Today',
                'lines' => [
                    sprintf('Model: %s', self::MODEL_NAME),
                    sprintf('Evaluated: %d', $evaluated),
                    'Result: No coin passed all bullish gate criteria.',
                ],
            ]);

            return;
        }

        $top = $rankedSignals[0];

        $this->notificationService->sendSystemMessage([
            'execution_id' => $executionId,
            'title' => 'Spot Momentum Gainer - Setup Found',
            'lines' => [
                sprintf('Model: %s', self::MODEL_NAME),
                sprintf('Evaluated: %d', $evaluated),
                sprintf('Shortlisted: %d', count($rankedSignals)),
                sprintf('Top: %s', (string) ($top['symbol'] ?? '-')),
                sprintf('Top score: %s', (string) ($top['total_score'] ?? '-')),
            ],
        ]);
    }
}
