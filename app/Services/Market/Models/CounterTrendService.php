<?php

namespace App\Services\Market\Models;

use App\Models\Coin;
use App\Models\CoinMarketData;
use App\Services\Market\MarketRegimeService;
use App\Services\Trading\ModelOutputStoreService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CounterTrendService
{
    private const MODEL_NAME = 'counter_trend';

    private const MODEL_VERSION = '2.0';

    private const MIN_VOLUME_USD = 5_000_000;

    private const MAX_RESULTS = 10;

    private const STRUCTURE_LIMIT = 120;

    private const ENTRY_LIMIT = 120;

    public function __construct(
        private readonly MarketRegimeService $marketRegimeService,
        private readonly ModelOutputStoreService $modelOutputStoreService,
    ) {}

    /**
     * Execute Model 1 (Counter Trend) scanning pipeline.
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
    public function execute(
        ?string $executionId = null,
        string $structureTimeframe = '1h',
        string $entryTimeframe = '15m',
    ): array {
        $resolvedExecutionId = $executionId ?: Str::uuid()->toString();

        Log::info('[CounterTrendService] Started execution', [
            'execution_id' => $resolvedExecutionId,
            'structure_timeframe' => $structureTimeframe,
            'entry_timeframe' => $entryTimeframe,
        ]);

        $candidates = $this->filterCoins();
        $signals = [];

        foreach ($candidates as $coin) {
            $structureKlines = $this->fetchAndStoreOhlcv(
                coin: $coin,
                timeframe: $structureTimeframe,
                limit: self::STRUCTURE_LIMIT,
            );

            $entryKlines = $this->fetchAndStoreOhlcv(
                coin: $coin,
                timeframe: $entryTimeframe,
                limit: self::ENTRY_LIMIT,
            );

            if ($structureKlines === [] || $entryKlines === []) {
                Log::warning('[CounterTrendService] Skipped coin due to missing OHLCV', [
                    'execution_id' => $resolvedExecutionId,
                    'symbol' => $coin->symbol,
                ]);

                continue;
            }

            $signal = $this->analyzeCoin(
                symbol: $coin->symbol,
                structureKlines: $structureKlines,
                entryKlines: $entryKlines,
                structureTimeframe: $structureTimeframe,
                entryTimeframe: $entryTimeframe,
            );

            if ($signal === null) {
                continue;
            }

            $signals[] = $signal;
        }

        usort(
            $signals,
            static fn(array $left, array $right): int => $right['total_score'] <=> $left['total_score'],
        );

        $ranked = array_values(array_map(
            static fn(array $signal, int $index): array => array_merge($signal, ['rank' => $index + 1]),
            array_slice($signals, 0, self::MAX_RESULTS),
            array_keys(array_slice($signals, 0, self::MAX_RESULTS)),
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
            'evaluated' => $candidates->count(),
            'shortlisted' => count($ranked),
            'requested_timeframes' => [
                'structure' => $structureTimeframe,
                'entry' => $entryTimeframe,
            ],
            'all_scored_results' => $signals,
        ];

        $this->modelOutputStoreService->store(
            modelName: self::MODEL_NAME,
            executionId: $resolvedExecutionId,
            executionDate: $executionDate,
            result: $standardOutput,
            supportingData: $supportingData,
        );

        $result = array_merge($standardOutput, [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => $supportingData['evaluated'],
            'shortlisted' => $supportingData['shortlisted'],
        ]);

        Log::info('[CounterTrendService] Completed execution', [
            'execution_id' => $resolvedExecutionId,
            'evaluated' => $result['evaluated'],
            'shortlisted' => $result['shortlisted'],
        ]);

        return $result;
    }

    /**
     * Fetch OHLCV from Binance and persist latest payload per coin+interval.
     *
     * @return array<int, array<int, mixed>>
     */
    private function fetchAndStoreOhlcv(Coin $coin, string $timeframe, int $limit): array
    {
        $klines = $this->marketRegimeService->getOhlcvDataForCoin(
            $coin->symbol,
            $timeframe,
            $limit,
        );

        if ($klines === []) {
            return [];
        }

        CoinMarketData::query()->updateOrCreate(
            [
                'coin_id' => $coin->id,
                'data_type' => 'ohlcv',
                'source' => 'binance',
                'interval' => $timeframe,
            ],
            [
                'data' => $klines,
            ],
        );

        return $klines;
    }

    /**
     * Layer 3 candidate selection for Model 1.
     */
    private function filterCoins()
    {
        return Coin::query()
            ->where('is_valid', true)
            ->whereRaw("COALESCE((raw_data->>'market_cap_rank')::int, 999999) BETWEEN ? AND ?", [50, 300])
            ->where(function ($query) {
                $query->where('total_volume', '>=', self::MIN_VOLUME_USD)
                    ->orWhere('volume_24h', '>=', self::MIN_VOLUME_USD);
            })
            ->get();
    }

    /**
     * @param  array<int, array<int, mixed>>  $structureKlines
     * @param  array<int, array<int, mixed>>  $entryKlines
     * @return array<string, mixed>|null
     */
    private function analyzeCoin(
        string $symbol,
        array $structureKlines,
        array $entryKlines,
        string $structureTimeframe,
        string $entryTimeframe,
    ): ?array {
        $structureCandles = $this->mapKlinesToCandles($structureKlines);
        $entryCandles = $this->mapKlinesToCandles($entryKlines);

        if (count($structureCandles) < 30 || count($entryCandles) < 30) {
            return null;
        }

        $sweep = $this->detectLiquiditySweep($structureCandles);

        if (! $sweep['confirmed']) {
            return null;
        }

        $mss = $this->detectMarketStructureShift($entryCandles, $sweep['direction']);

        if (! $mss) {
            return null;
        }

        $hasEntryZone = $this->hasEntryZoneFromFvgOrOrderBlock($entryCandles, $sweep['direction']);

        $sweepScore = 40;
        $mssScore = 30;
        $entryZoneScore = $hasEntryZone ? 15 : 0;
        $oiScore = 0;
        $fundingScore = 0;
        $totalScore = $sweepScore + $mssScore + $entryZoneScore + $oiScore + $fundingScore;

        $lastCandle = $entryCandles[array_key_last($entryCandles)];
        $currentPrice = (float) $lastCandle['close'];

        return [
            'symbol' => strtoupper($symbol) . 'USDT',
            'price' => round($currentPrice, 8),
            'total_score' => $totalScore,
            'components' => [
                'liquidity_sweep' => $sweep['confirmed'],
                'mss' => $mss,
                'fvg_or_ob' => $hasEntryZone,
                'oi_decline' => false,
                'funding_extreme' => false,
                'score_breakdown' => [
                    'sweep' => $sweepScore,
                    'mss' => $mssScore,
                    'fvg_or_ob' => $entryZoneScore,
                    'oi' => $oiScore,
                    'funding' => $fundingScore,
                ],
            ],
            'metadata' => [
                'strategy' => 'counter_trend',
                'direction' => $sweep['direction'],
                'entry_timeframe' => strtoupper($entryTimeframe),
                'structure_timeframe' => strtoupper($structureTimeframe),
                'reason' => sprintf(
                    'Sweep %s + MSS confirmed%s',
                    $sweep['direction'],
                    $hasEntryZone ? ' + entry zone' : ''
                ),
            ],
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $klines
     * @return array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>
     */
    private function mapKlinesToCandles(array $klines): array
    {
        $candles = [];

        foreach ($klines as $row) {
            if (! is_array($row) || count($row) < 6) {
                continue;
            }

            if (! is_numeric($row[0]) || ! is_numeric($row[1]) || ! is_numeric($row[2]) || ! is_numeric($row[3]) || ! is_numeric($row[4]) || ! is_numeric($row[5])) {
                continue;
            }

            $candles[] = [
                'open_time' => (int) $row[0],
                'open' => (float) $row[1],
                'high' => (float) $row[2],
                'low' => (float) $row[3],
                'close' => (float) $row[4],
                'volume' => (float) $row[5],
            ];
        }

        return $candles;
    }

    /**
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     * @return array{confirmed: bool, direction: string}
     */
    private function detectLiquiditySweep(array $candles): array
    {
        $lastIndex = array_key_last($candles);

        if ($lastIndex === null || $lastIndex < 21) {
            return ['confirmed' => false, 'direction' => 'none'];
        }

        $last = $candles[$lastIndex];
        $lookback = array_slice($candles, -22, 21);

        $recentHigh = max(array_column($lookback, 'high'));
        $recentLow = min(array_column($lookback, 'low'));

        $sweptHigh = $last['high'] > $recentHigh && $last['close'] < $recentHigh;
        $sweptLow = $last['low'] < $recentLow && $last['close'] > $recentLow;

        if ($sweptLow) {
            return ['confirmed' => true, 'direction' => 'bullish'];
        }

        if ($sweptHigh) {
            return ['confirmed' => true, 'direction' => 'bearish'];
        }

        return ['confirmed' => false, 'direction' => 'none'];
    }

    /**
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     */
    private function detectMarketStructureShift(array $candles, string $direction): bool
    {
        $lastIndex = array_key_last($candles);

        if ($lastIndex === null || $lastIndex < 21) {
            return false;
        }

        $last = $candles[$lastIndex];
        $lookback = array_slice($candles, -22, 21);
        $recentHigh = max(array_column($lookback, 'high'));
        $recentLow = min(array_column($lookback, 'low'));

        if ($direction === 'bullish') {
            return $last['close'] > $recentHigh;
        }

        if ($direction === 'bearish') {
            return $last['close'] < $recentLow;
        }

        return false;
    }

    /**
     * @param  array<int, array{open_time: int, open: float, high: float, low: float, close: float, volume: float}>  $candles
     */
    private function hasEntryZoneFromFvgOrOrderBlock(array $candles, string $direction): bool
    {
        if (count($candles) < 6) {
            return false;
        }

        $latest = $candles[array_key_last($candles)];
        $currentClose = (float) $latest['close'];

        $fvgFound = false;
        for ($i = count($candles) - 1; $i >= max(2, count($candles) - 15); $i--) {
            $left = $candles[$i - 2];
            $right = $candles[$i];

            if ($direction === 'bullish' && $left['high'] < $right['low']) {
                $fvgFound = true;

                break;
            }

            if ($direction === 'bearish' && $left['low'] > $right['high']) {
                $fvgFound = true;

                break;
            }
        }

        $obFound = false;
        $recentCandles = array_slice($candles, -10);

        foreach ($recentCandles as $candle) {
            $body = abs($candle['close'] - $candle['open']);
            $range = max(0.00000001, $candle['high'] - $candle['low']);
            $bodyRatio = $body / $range;

            if ($direction === 'bullish' && $candle['close'] < $candle['open'] && $bodyRatio >= 0.5) {
                if ($currentClose >= $candle['low'] && $currentClose <= $candle['high']) {
                    $obFound = true;

                    break;
                }
            }

            if ($direction === 'bearish' && $candle['close'] > $candle['open'] && $bodyRatio >= 0.5) {
                if ($currentClose >= $candle['low'] && $currentClose <= $candle['high']) {
                    $obFound = true;

                    break;
                }
            }
        }

        return $fvgFound || $obFound;
    }
}
