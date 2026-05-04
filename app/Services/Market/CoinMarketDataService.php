<?php

namespace App\Services\Market;

use App\Jobs\CoinMarketDataJob;
use App\Models\MarketIndicator;
use App\Models\MarketRaw;
use App\Services\External\BinanceFuturesService;
use App\Services\External\CoinGeckoService;
use App\Services\Indicator\IndicatorService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class CoinMarketDataService
{
    /** Minimum number of price data points required to compute indicators. */
    private const MIN_PRICES = 21;

    private const FEED_FAST = 'fast_5m';

    private const FEED_HOURLY = 'hourly';

    private const FEED_DAILY = 'daily';

    public function __construct(
        private readonly CoinGeckoService $coinGeckoService,
        private readonly BinanceFuturesService $binanceFuturesService,
        private readonly CoinUniverseService $coinUniverseService,
        private readonly IndicatorService $indicatorService,
    ) {}

    /**
     * Run the full ingestion pipeline for a single coin.
     *
     * @param  array<string>  $timeframes
     */
    public function ingest(string $coin, array $timeframes, string $executionId): void
    {
        $sortedTimeframes = $this->sortTimeframes($timeframes);
        $requiredFeeds = $this->resolveRequiredFeeds($sortedTimeframes);

        if ($requiredFeeds === []) {
            Log::warning('[CoinMarketDataService] Ingestion skipped — no supported timeframe configured', [
                'execution_id' => $executionId,
                'coin' => $coin,
                'timeframes' => $sortedTimeframes,
            ]);

            return;
        }

        $feedPayloads = $this->fetchRequiredMarketFeeds($coin, $requiredFeeds, $sortedTimeframes, $executionId);

        if ($feedPayloads === null) {
            return;
        }

        $derivatives = $this->fetchDerivativesSnapshot($coin, $executionId);

        foreach ($sortedTimeframes as $timeframe) {
            $processed = $this->processTimeframe(
                $coin,
                $timeframe,
                $feedPayloads,
                $derivatives,
                $sortedTimeframes,
                $executionId,
            );

            if (! $processed) {
                return;
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $feedPayloads
     * @param  array{open_interest: float|null, funding_rate: float|null, cvd: float|null, cvd_slope: float|null}  $derivatives
     * @param  array<string>  $sortedTimeframes
     */
    private function processTimeframe(
        string $coin,
        string $timeframe,
        array $feedPayloads,
        array $derivatives,
        array $sortedTimeframes,
        string $executionId,
    ): bool {
        $feedName = $this->resolveFeedForTimeframe($timeframe);

        if ($feedName === null) {
            return $this->retryAndAbort(
                '[CoinMarketDataService] Unsupported timeframe configuration',
                $coin,
                $sortedTimeframes,
                $executionId,
                ['timeframe' => $timeframe],
            );
        }

        $payload = $feedPayloads[$feedName] ?? null;

        if ($payload === null) {
            return $this->retryAndAbort(
                '[CoinMarketDataService] Missing required feed payload',
                $coin,
                $sortedTimeframes,
                $executionId,
                [
                    'feed' => $feedName,
                    'timeframe' => $timeframe,
                ],
            );
        }

        $series = $this->buildSeriesForTimeframe(
            (array) ($payload['price_points'] ?? []),
            $timeframe,
        );

        if (count($series['prices']) < self::MIN_PRICES) {
            return $this->retryAndAbort(
                '[CoinMarketDataService] Not enough derived data for timeframe',
                $coin,
                $sortedTimeframes,
                $executionId,
                [
                    'timeframe' => $timeframe,
                    'feed' => $feedName,
                    'count' => count($series['prices']),
                    'required' => self::MIN_PRICES,
                ],
            );
        }

        $indicators = $this->indicatorService->calculateFromPrices($series['prices']);

        if ($indicators === null) {
            return $this->retryAndAbort(
                '[CoinMarketDataService] Indicator calculation returned null',
                $coin,
                $sortedTimeframes,
                $executionId,
                ['timeframe' => $timeframe],
            );
        }

        $lastTimestamp = end($series['timestamps']);

        if (! is_int($lastTimestamp)) {
            return $this->retryAndAbort(
                '[CoinMarketDataService] Missing candle close timestamp',
                $coin,
                $sortedTimeframes,
                $executionId,
                ['timeframe' => $timeframe],
            );
        }

        $this->storeIndicator(
            $coin,
            $timeframe,
            Carbon::createFromTimestampUTC($lastTimestamp),
            $indicators,
            $derivatives,
            $executionId,
        );

        return true;
    }

    /**
     * @param  array<string>  $timeframes
     * @return array<string, array{days: int, interval: string|null, endpoint: string}>
     */
    private function resolveRequiredFeeds(array $timeframes): array
    {
        $feeds = [];

        foreach ($timeframes as $timeframe) {
            $feedName = $this->resolveFeedForTimeframe($timeframe);

            if ($feedName === self::FEED_FAST) {
                $feeds[self::FEED_FAST] = [
                    'days' => 1,
                    'interval' => null,
                    'endpoint' => 'market_chart_auto_1d',
                ];
            }

            if ($feedName === self::FEED_HOURLY) {
                $feeds[self::FEED_HOURLY] = [
                    'days' => 30,
                    'interval' => 'hourly',
                    'endpoint' => 'market_chart_hourly_30d',
                ];
            }

            if ($feedName === self::FEED_DAILY) {
                $feeds[self::FEED_DAILY] = [
                    'days' => 30,
                    'interval' => 'daily',
                    'endpoint' => 'market_chart_daily_30d',
                ];
            }
        }

        return $feeds;
    }

    /**
     * @param  array<string, array{days: int, interval: string|null, endpoint: string}>  $requiredFeeds
     * @param  array<string>  $sortedTimeframes
     * @return array<string, array<string, mixed>>|null
     */
    private function fetchRequiredMarketFeeds(string $coin, array $requiredFeeds, array $sortedTimeframes, string $executionId): ?array
    {
        $result = [];

        foreach ($requiredFeeds as $feedName => $feedSpec) {
            $payload = $this->coinGeckoService->fetchMarketChart(
                $coin,
                (int) $feedSpec['days'],
                $feedSpec['interval'],
            );

            if ($payload === null) {
                $this->retryAndAbort(
                    '[CoinMarketDataService] Required CoinGecko feed failed',
                    $coin,
                    $sortedTimeframes,
                    $executionId,
                    [
                        'feed' => $feedName,
                        'days' => $feedSpec['days'],
                        'interval' => $feedSpec['interval'],
                    ],
                );

                return null;
            }

            $this->storeRaw(
                coin: $coin,
                endpoint: $feedSpec['endpoint'],
                source: 'coingecko',
                timestamp: now(),
                requestParams: (array) ($payload['request_params'] ?? []),
                rawResponse: (array) ($payload['raw_response'] ?? []),
                executionId: $executionId,
            );

            $result[$feedName] = $payload;
        }

        return $result;
    }

    /**
     * Log error details, schedule a retry, and return false for early abort flow.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string>  $sortedTimeframes
     */
    private function retryAndAbort(
        string $message,
        string $coin,
        array $sortedTimeframes,
        string $executionId,
        array $context = [],
    ): bool {
        Log::error($message, array_merge([
            'execution_id' => $executionId,
            'coin' => $coin,
        ], $context));

        $this->dispatchRetry($coin, $sortedTimeframes, $executionId);

        return false;
    }

    /**
     * Re-dispatch the coin ingestion job when feed or derivation is incomplete.
     *
     * @param  array<string>  $sortedTimeframes
     */
    private function dispatchRetry(string $coin, array $sortedTimeframes, string $executionId): void
    {
        $delaySeconds = random_int(10, 30);

        CoinMarketDataJob::dispatch($coin, $sortedTimeframes, $executionId)
            ->delay(now()->addSeconds($delaySeconds));

        Log::warning('[CoinMarketDataService] Coin job re-dispatched', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframes' => $sortedTimeframes,
            'delay_seconds' => $delaySeconds,
        ]);
    }

    /**
     * Persist one raw API payload for observability and replay.
     *
     * @param  array<string, mixed>  $requestParams
     * @param  array<string, mixed>  $rawResponse
     */
    private function storeRaw(
        string $coin,
        string $endpoint,
        string $source,
        Carbon $timestamp,
        array $requestParams,
        array $rawResponse,
        string $executionId,
    ): void {
        $record = new MarketRaw;
        $record->execution_id = $executionId;
        $record->coin = $coin;
        $record->endpoint = $endpoint;
        $record->timestamp = $timestamp;
        $record->request_params = $requestParams;
        $record->response_json = $rawResponse;
        $record->source = $source;
        $record->save();

        Log::info('[CoinMarketDataService] Raw API response stored', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'endpoint' => $endpoint,
            'source' => $source,
            'raw_id' => $record->id,
            'timestamp' => $timestamp->toIso8601String(),
        ]);
    }

    /**
     * Persist computed indicator data for a single timeframe.
     *
     * @param  array{price: float, rsi: float, ema9: float, ema21: float, trend: string}  $indicators
     * @param  array{open_interest: float|null, funding_rate: float|null, cvd: float|null, cvd_slope: float|null}  $derivatives
     */
    private function storeIndicator(string $coin, string $timeframe, Carbon $timestamp, array $indicators, array $derivatives, string $executionId): void
    {
        $record = MarketIndicator::updateOrCreate(
            [
                'coin' => $coin,
                'timeframe' => $timeframe,
                'timestamp' => $timestamp,
            ],
            [
                'execution_id' => $executionId,
                'price' => $indicators['price'],
                'rsi' => $indicators['rsi'],
                'ema9' => $indicators['ema9'],
                'ema21' => $indicators['ema21'],
                'trend' => $indicators['trend'],
                'open_interest' => $derivatives['open_interest'],
                'funding_rate' => $derivatives['funding_rate'],
                'cvd' => $derivatives['cvd'],
                'cvd_slope' => $derivatives['cvd_slope'],
                'source' => 'coingecko+binance_futures',
            ]
        );

        Log::info('[CoinMarketDataService] Indicator result stored', [
            'execution_id' => $executionId,
            'coin' => $coin,
            'timeframe' => $timeframe,
            'indicator_id' => $record->id,
            'price' => $indicators['price'],
            'rsi' => $indicators['rsi'],
            'ema9' => $indicators['ema9'],
            'ema21' => $indicators['ema21'],
            'trend' => $indicators['trend'],
            'open_interest' => $derivatives['open_interest'],
            'funding_rate' => $derivatives['funding_rate'],
            'cvd' => $derivatives['cvd'],
            'cvd_slope' => $derivatives['cvd_slope'],
            'timestamp' => $timestamp->toIso8601String(),
        ]);
    }

    /**
     * @return array{open_interest: float|null, funding_rate: float|null, cvd: float|null, cvd_slope: float|null}
     */
    private function fetchDerivativesSnapshot(string $coin, string $executionId): array
    {
        $symbol = $this->resolveBinanceSymbol($coin);

        if ($symbol === null) {
            Log::debug('[CoinMarketDataService] Derivatives skipped: missing futures symbol', [
                'execution_id' => $executionId,
                'coin' => $coin,
            ]);

            return [
                'open_interest' => null,
                'funding_rate' => null,
                'cvd' => null,
                'cvd_slope' => null,
            ];
        }

        $openInterestPayload = $this->binanceFuturesService->fetchOpenInterest($symbol);
        $fundingPayload = $this->binanceFuturesService->fetchLatestFundingRate($symbol);
        $aggTradesPayload = $this->binanceFuturesService->fetchAggTrades($symbol, 200);

        if ($openInterestPayload !== null) {
            $this->storeRaw(
                coin: $coin,
                endpoint: 'fapi_v1_openInterest',
                source: 'binance_futures',
                timestamp: now(),
                requestParams: $openInterestPayload['request_params'],
                rawResponse: $openInterestPayload['raw_response'],
                executionId: $executionId,
            );
        }

        if ($fundingPayload !== null) {
            $this->storeRaw(
                coin: $coin,
                endpoint: 'fapi_v1_fundingRate',
                source: 'binance_futures',
                timestamp: now(),
                requestParams: $fundingPayload['request_params'],
                rawResponse: $fundingPayload['raw_response'],
                executionId: $executionId,
            );
        }

        if ($aggTradesPayload !== null) {
            $this->storeRaw(
                coin: $coin,
                endpoint: 'fapi_v1_aggTrades',
                source: 'binance_futures',
                timestamp: now(),
                requestParams: $aggTradesPayload['request_params'],
                rawResponse: $aggTradesPayload['raw_response'],
                executionId: $executionId,
            );
        }

        $cvd = null;
        $cvdSlope = null;

        if ($aggTradesPayload !== null) {
            $cvdMetrics = $this->binanceFuturesService->calculateCvdMetrics($aggTradesPayload['trades']);
            $cvd = $cvdMetrics['cvd'];
            $cvdSlope = $cvdMetrics['cvd_slope'];
        }

        return [
            'open_interest' => $openInterestPayload['open_interest'] ?? null,
            'funding_rate' => $fundingPayload['funding_rate'] ?? null,
            'cvd' => $cvd,
            'cvd_slope' => $cvdSlope,
        ];
    }

    /**
     * Build timeframe-aligned close-price series from [timestamp, price] points.
     *
     * @param  array<int, array{timestamp_ms: int, price: float}>  $pricePoints
     * @return array{prices: array<int, float>, timestamps: array<int, int>}
     */
    private function buildSeriesForTimeframe(array $pricePoints, string $timeframe): array
    {
        $targetMinutes = $this->timeframeToMinutes($timeframe);

        if ($targetMinutes === PHP_INT_MAX || $targetMinutes <= 0) {
            return [
                'prices' => [],
                'timestamps' => [],
            ];
        }

        $bucketSeconds = $targetMinutes * 60;
        $nowSeconds = now()->utc()->timestamp;

        /** @var array<int, float> $bucketCloses */
        $bucketCloses = [];

        foreach ($pricePoints as $point) {
            $timestampMs = (int) ($point['timestamp_ms'] ?? 0);
            $price = (float) ($point['price'] ?? 0.0);

            if ($timestampMs <= 0) {
                continue;
            }

            $timestampSeconds = intdiv($timestampMs, 1000);
            $bucketStart = intdiv($timestampSeconds, $bucketSeconds) * $bucketSeconds;
            $bucketClose = $bucketStart + $bucketSeconds;

            // Use only closed candles to avoid unstable last-candle indicators.
            if ($bucketClose > $nowSeconds) {
                continue;
            }

            $bucketCloses[$bucketStart] = $price;
        }

        if ($bucketCloses === []) {
            return [
                'prices' => [],
                'timestamps' => [],
            ];
        }

        ksort($bucketCloses);

        $prices = [];
        $timestamps = [];

        foreach ($bucketCloses as $bucketStart => $closePrice) {
            $prices[] = (float) $closePrice;
            $timestamps[] = (int) $bucketStart + $bucketSeconds;
        }

        return [
            'prices' => $prices,
            'timestamps' => $timestamps,
        ];
    }

    private function resolveFeedForTimeframe(string $timeframe): ?string
    {
        $normalized = strtolower(trim($timeframe));

        if (preg_match('/^\d+m$/', $normalized) === 1) {
            return self::FEED_FAST;
        }

        if (preg_match('/^\d+h$/', $normalized) === 1) {
            return self::FEED_HOURLY;
        }

        if (preg_match('/^\d+d$/', $normalized) === 1) {
            return self::FEED_DAILY;
        }

        return null;
    }

    /**
     * @param  array<string>  $timeframes
     * @return array<string>
     */
    private function sortTimeframes(array $timeframes): array
    {
        $unique = array_values(array_unique($timeframes));

        usort($unique, fn(string $a, string $b): int => $this->timeframeToMinutes($a) <=> $this->timeframeToMinutes($b));

        return $unique;
    }

    private function timeframeToMinutes(string $timeframe): int
    {
        if (preg_match('/^(\d+)m$/i', trim($timeframe), $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^(\d+)h$/i', trim($timeframe), $matches) === 1) {
            return ((int) $matches[1]) * 60;
        }

        if (preg_match('/^(\d+)d$/i', trim($timeframe), $matches) === 1) {
            return ((int) $matches[1]) * 60 * 24;
        }

        return PHP_INT_MAX;
    }

    private function resolveBinanceSymbol(string $coin): ?string
    {
        $universe = $this->coinUniverseService->getCachedUniverse();

        foreach ($universe as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['coin'] ?? null) !== $coin) {
                continue;
            }

            $symbol = strtoupper((string) ($entry['symbol'] ?? ''));

            if ($symbol === '') {
                return null;
            }

            return str_ends_with($symbol, 'USDT') ? $symbol : ($symbol . 'USDT');
        }

        return null;
    }
}
