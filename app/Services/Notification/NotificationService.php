<?php

namespace App\Services\Notification;

use App\Models\GeneralConfig;
use App\Models\ModelScanResult;
use App\Services\Trading\ExchangeRateRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Telegram\Bot\Laravel\Facades\Telegram;
use Throwable;

/**
 * NotificationService
 *
 * Responsible for dispatching trade signal notifications to configured channels.
 *
 * This service has no business logic — it only formats and sends notifications.
 * Callers must pre-filter decisions before invoking this service.
 *
 * Currently logs the notification. Extend this class to support Slack, Telegram,
 * email, or any other channel without changing the caller.
 */
class NotificationService
{
    public function __construct(
        private ExchangeRateRepository $rateRepository,
    ) {}

    /**
     * Send model execution notifications:
     * - One summary message per model execution
     * - One message per passed/shortlisted coin
     *
     * @param  array{
     *   execution_id: string,
     *   model: string,
     *   evaluated: int,
     *   shortlisted: int,
     *   results: array<int, array<string, mixed>>,
     * }  $payload
     */
    public function sendModelExecutionResult(array $payload): void
    {
        $executionId = (string) $payload['execution_id'];
        $model = (string) $payload['model'];
        $evaluated = (int) $payload['evaluated'];
        $shortlisted = (int) $payload['shortlisted'];
        $results = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $detailRows = $this->resolvePassedDetailRows(
            executionId: $executionId,
            model: $model,
            fallbackResults: $results,
        );
        $modelDisplayName = $this->formatModelName($model);

        if ($detailRows === [] || $shortlisted === 0) {
            Log::info('[NotificationService] Skipping model execution notification because no coin passed', [
                'execution_id' => $executionId,
                'model' => $model,
                'evaluated' => $evaluated,
                'shortlisted' => $shortlisted,
            ]);

            return;
        }

        $top = $detailRows[0] ?? [];
        $topSymbol = (string) ($top['symbol'] ?? '-');
        $topScore = is_numeric($top['score'] ?? null)
            ? (string) $top['score']
            : '-';
        $passedCoinsSummary = $this->buildPassedCoinsSummary($detailRows);

        $this->sendSystemMessage([
            'execution_id' => $executionId,
            'title' => sprintf('%s - Execution Summary', $modelDisplayName),
            'lines' => [
                sprintf('Model: %s', $modelDisplayName),
                sprintf('Evaluated: %d', $evaluated),
                sprintf('Passed: %d', $shortlisted),
                sprintf('Coins: %s', $passedCoinsSummary),
                sprintf('Top: %s', $topSymbol),
                sprintf('Top score: %s', $topScore),
            ],
        ]);

        foreach ($detailRows as $detailRow) {
            $message = $this->buildModelSetupAnalysisMessage(
                row: $detailRow,
                model: $model,
                modelDisplayName: $modelDisplayName,
                executionId: $executionId,
            );

            $this->sendTelegramMessage(
                message: $message,
                executionId: $executionId,
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $fallbackResults
     * @return array<int, array<string, mixed>>
     */
    private function resolvePassedDetailRows(string $executionId, string $model, array $fallbackResults): array
    {
        $modelResult = ModelScanResult::query()
            ->where('execution_id', $executionId)
            ->where('model_name', $model)
            ->latest('id')
            ->first();

        if ($modelResult !== null) {
            $details = $modelResult->details()
                ->with('coin:id,symbol')
                ->where('is_passed', true)
                ->orderByRaw('CASE WHEN rank = 0 THEN 9999 ELSE rank END')
                ->orderByDesc('score')
                ->get();

            if ($details->isNotEmpty()) {
                return $details->map(function ($detail): array {
                    $data = is_array($detail->data) ? $detail->data : [];
                    $signal = is_array($data['signal'] ?? null) ? $data['signal'] : [];
                    $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
                    $components = is_array($data['components'] ?? null)
                        ? $data['components']
                        : (is_array($signal['components'] ?? null) ? $signal['components'] : []);

                    return [
                        'rank' => (int) ($signal['rank'] ?? $detail->rank ?: 0),
                        'symbol' => (string) ($data['symbol'] ?? $signal['symbol'] ?? ($detail->coin?->symbol ?? '-')),
                        'price' => $signal['price'] ?? $data['price'] ?? $detail->price,
                        'score' => $signal['total_score'] ?? $data['score'] ?? $detail->score,
                        'signal' => $signal,
                        'metadata' => $metadata,
                        'components' => $components,
                        'data' => $data,
                    ];
                })->values()->all();
            }
        }

        return array_values(array_map(function (array $result, int $index): array {
            $signal = is_array($result['signal'] ?? null) ? $result['signal'] : $result;
            $metadata = is_array($signal['metadata'] ?? null)
                ? $signal['metadata']
                : (is_array($result['metadata'] ?? null) ? $result['metadata'] : []);
            $components = is_array($signal['components'] ?? null)
                ? $signal['components']
                : (is_array($result['components'] ?? null) ? $result['components'] : []);

            return [
                'rank' => (int) ($signal['rank'] ?? ($result['rank'] ?? ($index + 1))),
                'symbol' => (string) ($signal['symbol'] ?? $result['symbol'] ?? '-'),
                'price' => $signal['price'] ?? $result['price'] ?? null,
                'score' => $signal['total_score'] ?? $result['score'] ?? null,
                'signal' => $signal,
                'metadata' => $metadata,
                'components' => $components,
                'data' => $result,
            ];
        }, $fallbackResults, array_keys($fallbackResults)));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildModelSetupAnalysisMessage(array $row, string $model, string $modelDisplayName, ?string $executionId): string
    {
        return match ($model) {
            'counter_trend' => $this->buildCounterTrendSetupMessage($row, $executionId),
            'pre_pump' => $this->buildPrePumpSetupMessage($row, $executionId),
            'momentum' => $this->buildTrendMomentumSetupMessage($row, $executionId),
            'spot_momentum_gainer' => $this->buildSpotMomentumSetupMessage($row, $executionId),
            'daily_safe_momentum' => $this->buildDailySafeMomentumSetupMessage($row, $executionId),
            default => $this->buildGenericSetupMessage($row, $modelDisplayName, $executionId),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildCounterTrendSetupMessage(array $row, ?string $executionId): string
    {
        $symbol = strtoupper((string) ($row['symbol'] ?? '-'));
        $signal = is_array($row['signal'] ?? null) ? $row['signal'] : [];
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $components = is_array($row['components'] ?? null) ? $row['components'] : [];

        $directionRaw = strtolower((string) ($components['liquidity_sweep'] ?? $components['mss'] ?? '-'));
        $bias = $directionRaw === 'bullish' ? 'LONG' : ($directionRaw === 'bearish' ? 'SHORT' : '-');
        $biasIcon = $bias === 'LONG' ? '📈' : ($bias === 'SHORT' ? '📉' : '➖');

        $price = $this->formatPriceMultiCurrency($row['price'] ?? null);
        $score = $this->formatScore($row['score'] ?? null);
        $entryTf = strtoupper((string) ($metadata['entry_timeframe'] ?? '15M'));
        $structureTf = strtoupper((string) ($metadata['structure_timeframe'] ?? '1H'));
        $macroTf = strtoupper((string) ($metadata['macro_timeframe'] ?? '4H'));
        $stopLoss = $this->formatPriceMultiCurrency($metadata['stop_loss'] ?? null);
        $zone = (string) ($metadata['fvg_zone_15m'] ?? 'N/A');
        $confluence = ((bool) ($metadata['macro_aligned'] ?? false)) ? 'macro aligned' : 'macro mixed';

        $lines = [
            sprintf('🎯 SETUP ANALYSIS — %s', $this->escapeForHtml($symbol)),
            '━━━━━━━━━━━━━━━━━━━━━',
            sprintf('💰 Live Price: %s', $this->escapeForHtml($price)),
            '',
            sprintf('🧭 HTF CONTEXT (%s + %s)', strtolower($structureTf), strtolower($macroTf)),
            sprintf('%s: %s %s bias', $this->escapeForHtml($macroTf), $biasIcon, $this->escapeForHtml($bias)),
            sprintf('%s: %s %s bias', $this->escapeForHtml($structureTf), $biasIcon, $this->escapeForHtml($bias)),
            sprintf('Zone (%s): %s', $this->escapeForHtml($entryTf), $this->escapeForHtml($zone)),
            sprintf('✅ HTF Confluence: %s', $this->escapeForHtml($confluence)),
            '',
            sprintf('📊 MTF REGIME (%s)', strtolower($entryTf)),
            sprintf('Regime: %s', $this->escapeForHtml((string) ($metadata['strategy'] ?? 'counter-trend'))),
            sprintf(
                'Coinalyze: %s · OI points: %s · Funding points: %s',
                $this->escapeForHtml(((bool) ($metadata['coinalyze_available'] ?? false)) ? 'on' : 'off'),
                $this->escapeForHtml((string) ($metadata['oi_points'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['funding_points'] ?? '-')),
            ),
            '',
            sprintf('🎯 LTF TRIGGERS (%s / %s)', strtolower($entryTf), strtolower($structureTf)),
            sprintf('%s %s setup', $biasIcon, $this->escapeForHtml($bias)),
            sprintf('👑 Score %s', $this->escapeForHtml($score)),
            sprintf('Entry: %s', $this->escapeForHtml($price)),
            sprintf('SL: %s', $this->escapeForHtml($stopLoss)),
            sprintf('Factors: %s', $this->escapeForHtml($this->formatFactors($components))),
        ];

        if ($executionId !== null && $executionId !== '') {
            $lines[] = sprintf('Execution ID: %s', $this->escapeForHtml($executionId));
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildPrePumpSetupMessage(array $row, ?string $executionId): string
    {
        $symbol = strtoupper((string) ($row['symbol'] ?? '-'));
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $components = is_array($row['components'] ?? null) ? $row['components'] : [];

        $price = $this->formatPriceMultiCurrency($row['price'] ?? null);
        $score = $this->formatScore($row['score'] ?? null);
        $entryTf = strtoupper((string) ($metadata['entry_timeframe'] ?? '1H'));
        $structureTf = strtoupper((string) ($metadata['structure_timeframe'] ?? '4H'));

        $lines = [
            sprintf('🎯 SETUP ANALYSIS — %s', $this->escapeForHtml($symbol)),
            '━━━━━━━━━━━━━━━━━━━━━',
            sprintf('💰 Live Price: %s', $this->escapeForHtml($price)),
            '',
            sprintf('🧭 HTF CONTEXT (%s + %s)', strtolower($entryTf), strtolower($structureTf)),
            sprintf('Funding 8H: %s', $this->escapeForHtml((string) ($metadata['funding_recent_8h'] ?? '-'))),
            sprintf('OI Growth 24H: %s', $this->escapeForHtml((string) ($metadata['oi_24h_growth'] ?? '-'))),
            sprintf('Price Range 24H: %s', $this->escapeForHtml((string) ($metadata['price_range_24h'] ?? '-'))),
            sprintf(
                'ATR14/Baseline: %s / %s',
                $this->escapeForHtml((string) ($metadata['atr_14'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['atr_30d_baseline'] ?? '-')),
            ),
            sprintf(
                'CVD slope: %s · RSI: %s',
                $this->escapeForHtml((string) ($metadata['cvd_slope_24h'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['rsi_recent_4h'] ?? '-')),
            ),
            '',
            sprintf('📊 MTF REGIME (%s)', strtolower($entryTf)),
            sprintf('Regime: %s', $this->escapeForHtml((string) ($metadata['strategy'] ?? 'pre-pump accumulation'))),
            sprintf('Volume ratio: %s', $this->escapeForHtml((string) ($metadata['volume_ratio'] ?? '-'))),
            '',
            '🎯 LTF TRIGGERS (signal components)',
            sprintf('👑 Score %s', $this->escapeForHtml($score)),
            sprintf('Factors: %s', $this->escapeForHtml($this->formatFactors($components))),
        ];

        if ($executionId !== null && $executionId !== '') {
            $lines[] = sprintf('Execution ID: %s', $this->escapeForHtml($executionId));
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildTrendMomentumSetupMessage(array $row, ?string $executionId): string
    {
        $symbol = strtoupper((string) ($row['symbol'] ?? '-'));
        $signal = is_array($row['signal'] ?? null) ? $row['signal'] : [];
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $components = is_array($row['components'] ?? null) ? $row['components'] : [];

        $price = $this->formatPriceMultiCurrency($row['price'] ?? null);
        $score = $this->formatScore($row['score'] ?? null);
        $entryTf = strtoupper((string) ($metadata['entry_timeframe'] ?? '4H'));
        $structureTf = strtoupper((string) ($metadata['structure_timeframe'] ?? '1D'));
        $stopLoss = $this->formatPriceMultiCurrency($metadata['stop_loss'] ?? null);

        $lines = [
            sprintf('🎯 SETUP ANALYSIS — %s', $this->escapeForHtml($symbol)),
            '━━━━━━━━━━━━━━━━━━━━━',
            sprintf('💰 Live Price: %s', $this->escapeForHtml($price)),
            '',
            sprintf('🧭 HTF CONTEXT (%s + %s)', strtolower($entryTf), strtolower($structureTf)),
            sprintf(
                'EMA Gate: close %s > ema50 %s > ema200 %s',
                $this->escapeForHtml((string) ($metadata['close'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['ema50'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['ema200'] ?? '-')),
            ),
            sprintf(
                'MACD: %s · Signal: %s · Hist: %s',
                $this->escapeForHtml((string) ($metadata['macd'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['signal'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['histogram'] ?? '-')),
            ),
            sprintf(
                'RSI: %s · BOS: %s',
                $this->escapeForHtml((string) ($metadata['rsi'] ?? '-')),
                $this->escapeForHtml(((bool) ($metadata['bos_ok'] ?? false)) ? 'yes' : 'no'),
            ),
            sprintf('✅ HTF Confluence: %s', $this->escapeForHtml(((bool) ($metadata['ema_gate_ok'] ?? false)) ? 'trend aligned' : 'mixed')),
            '',
            sprintf('📊 MTF REGIME (%s)', strtolower($entryTf)),
            sprintf('Regime: %s', $this->escapeForHtml((string) ($metadata['strategy'] ?? 'trend momentum'))),
            sprintf(
                'OI growth: %s · CVD slope: %s',
                $this->escapeForHtml((string) ($metadata['oi_growth'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['cvd_slope'] ?? '-')),
            ),
            '',
            sprintf('🎯 LTF TRIGGERS (%s)', strtolower($entryTf)),
            sprintf('📈 LONG · %s', $this->escapeForHtml((string) ($metadata['strategy'] ?? 'momentum'))),
            sprintf('👑 Score %s', $this->escapeForHtml($score)),
            sprintf('Entry: %s', $this->escapeForHtml($this->formatPriceWithDollar($signal['price'] ?? $row['price'] ?? null))),
            sprintf('SL: %s', $this->escapeForHtml($stopLoss)),
            sprintf('Factors: %s', $this->escapeForHtml($this->formatFactors($components))),
        ];

        if ($executionId !== null && $executionId !== '') {
            $lines[] = sprintf('Execution ID: %s', $this->escapeForHtml($executionId));
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildSpotMomentumSetupMessage(array $row, ?string $executionId): string
    {
        $symbol = strtoupper((string) ($row['symbol'] ?? '-'));
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $components = is_array($row['components'] ?? null) ? $row['components'] : [];

        $price = $this->formatPriceMultiCurrency($row['price'] ?? null);
        $score = $this->formatScore($row['score'] ?? null);
        $structureTf = strtoupper((string) ($metadata['structure_timeframe'] ?? '1D'));
        $entryTf = strtoupper((string) ($metadata['entry_timeframe'] ?? '1D'));
        $entry = $this->formatPriceMultiCurrency($metadata['entry_point'] ?? $row['price'] ?? null);
        $stopLoss = $this->formatPriceMultiCurrency($metadata['stop_loss'] ?? null);

        $lines = [
            sprintf('🎯 SETUP ANALYSIS — %s', $this->escapeForHtml($symbol)),
            '━━━━━━━━━━━━━━━━━━━━━',
            sprintf('💰 Live Price: %s', $this->escapeForHtml($price)),
            '',
            sprintf('🧭 HTF CONTEXT (%s + %s)', strtolower($entryTf), strtolower($structureTf)),
            sprintf('24H Change: %s%%', $this->escapeForHtml((string) ($metadata['price_change_percentage_24h'] ?? '-'))),
            sprintf(
                'Body ratio: %s · Volume ratio: %s',
                $this->escapeForHtml((string) ($metadata['body_ratio'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['volume_ratio'] ?? '-')),
            ),
            sprintf('Prior high: %s', $this->escapeForHtml((string) ($metadata['prior_high'] ?? '-'))),
            sprintf('✅ HTF Confluence: %s', $this->escapeForHtml(((bool) ($components['bullish_candle_gate'] ?? false)) ? 'breakout confirmed' : 'mixed')),
            '',
            sprintf('📊 MTF REGIME (%s)', strtolower($entryTf)),
            sprintf('Regime: %s', $this->escapeForHtml((string) ($metadata['strategy'] ?? 'spot momentum gainer'))),
            sprintf('Source: %s', $this->escapeForHtml((string) ($metadata['data_source'] ?? '-'))),
            '',
            sprintf('🎯 LTF TRIGGERS (%s)', strtolower($entryTf)),
            '📈 LONG · spot-only breakout',
            sprintf('👑 Score %s', $this->escapeForHtml($score)),
            sprintf('Entry: %s', $this->escapeForHtml($entry)),
            sprintf('SL: %s', $this->escapeForHtml($stopLoss)),
            sprintf('Factors: %s', $this->escapeForHtml($this->formatFactors($components))),
        ];

        if ($executionId !== null && $executionId !== '') {
            $lines[] = sprintf('Execution ID: %s', $this->escapeForHtml($executionId));
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildDailySafeMomentumSetupMessage(array $row, ?string $executionId): string
    {
        $symbol = strtoupper((string) ($row['symbol'] ?? '-'));
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $components = is_array($row['components'] ?? null) ? $row['components'] : [];

        $price = $this->formatPriceMultiCurrency($row['price'] ?? null);
        $score = $this->formatScore($row['score'] ?? null);
        $grade = strtoupper((string) ($metadata['confidence_grade'] ?? '-'));
        $entryTf = strtoupper((string) ($metadata['entry_timeframe'] ?? '1H'));
        $structureTf = strtoupper((string) ($metadata['structure_timeframe'] ?? '4H'));
        $marketTf = strtoupper((string) ($metadata['market_filter_timeframe'] ?? '1D'));
        $entry = $this->formatPriceMultiCurrency($metadata['entry_point'] ?? $row['price'] ?? null);
        $stopLoss = $this->formatPriceMultiCurrency($metadata['stop_loss'] ?? null);

        $lines = [
            sprintf('🎯 SETUP ANALYSIS — %s', $this->escapeForHtml($symbol)),
            '━━━━━━━━━━━━━━━━━━━━━',
            sprintf('💰 Live Price: %s', $this->escapeForHtml($price)),
            '',
            sprintf('🧭 HTF CONTEXT (%s + %s + %s)', strtolower($entryTf), strtolower($structureTf), strtolower($marketTf)),
            'Market gate: BTC safety passed',
            sprintf(
                'EMA: %s > %s > %s',
                $this->escapeForHtml((string) ($metadata['ema20'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['ema50'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['ema200'] ?? '-')),
            ),
            sprintf(
                'RSI: %s · MACD/Signal: %s / %s',
                $this->escapeForHtml((string) ($metadata['rsi_trend'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['macd'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['signal'] ?? '-')),
            ),
            sprintf('✅ Pullback quality: %s%% depth, %s candles', $this->escapeForHtml((string) ($metadata['pullback_depth_pct'] ?? '-')), $this->escapeForHtml((string) ($metadata['pullback_duration'] ?? '-'))),
            '',
            sprintf('📊 MTF REGIME (%s)', strtolower($entryTf)),
            sprintf('Regime: %s', $this->escapeForHtml((string) ($metadata['strategy'] ?? 'daily safe momentum'))),
            sprintf('Anti-euphoria: %s', $this->escapeForHtml(((bool) ($components['anti_euphoria_passed'] ?? false)) ? 'passed' : 'failed')),
            '',
            sprintf('🎯 LTF TRIGGERS (%s)', strtolower($entryTf)),
            '📈 LONG · continuation only',
            sprintf('👑 Score %s · Grade %s', $this->escapeForHtml($score), $this->escapeForHtml($grade)),
            sprintf('Entry: %s', $this->escapeForHtml($entry)),
            sprintf('SL: %s', $this->escapeForHtml($stopLoss)),
            sprintf('Factors: %s', $this->escapeForHtml($this->formatFactors($components))),
        ];

        if ($executionId !== null && $executionId !== '') {
            $lines[] = sprintf('Execution ID: %s', $this->escapeForHtml($executionId));
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function buildGenericSetupMessage(array $row, string $modelDisplayName, ?string $executionId): string
    {
        $symbol = strtoupper((string) ($row['symbol'] ?? '-'));
        $signal = is_array($row['signal'] ?? null) ? $row['signal'] : [];
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $components = is_array($row['components'] ?? null) ? $row['components'] : [];

        $price = $this->formatPriceMultiCurrency($row['price'] ?? null);
        $score = $this->formatScore($row['score'] ?? null);
        $direction = strtoupper((string) ($signal['direction'] ?? $metadata['direction'] ?? $metadata['bias'] ?? '-'));
        $strategy = (string) ($metadata['strategy'] ?? $signal['strategy'] ?? $modelDisplayName);
        $entryTimeframe = strtoupper((string) ($metadata['entry_timeframe'] ?? '15M'));
        $structureTimeframe = strtoupper((string) ($metadata['structure_timeframe'] ?? '1H'));
        $macroTimeframe = strtoupper((string) ($metadata['macro_timeframe'] ?? '4H'));
        $stopLoss = $this->formatPriceMultiCurrency($signal['stop_loss'] ?? $metadata['stop_loss'] ?? null);
        $entry = $this->formatPriceMultiCurrency($signal['entry'] ?? $signal['price'] ?? $row['price'] ?? null);
        $takeProfit = $this->formatPriceWithDollar($signal['take_profit'] ?? $metadata['take_profit'] ?? null);
        $takeProfit2 = $this->formatPriceWithDollar($signal['take_profit_2'] ?? $metadata['take_profit_2'] ?? null);
        $riskReward = (string) ($metadata['risk_reward'] ?? $metadata['rr'] ?? '-');

        $factors = $this->formatFactors($components);
        $reason = (string) ($metadata['reason'] ?? $signal['reason'] ?? 'Passed model criteria.');

        $lines = [
            sprintf('🎯 SETUP ANALYSIS — %s', $this->escapeForHtml($symbol)),
            '━━━━━━━━━━━━━━━━━━━━━',
            sprintf('💰 Live Price: %s', $this->escapeForHtml($price)),
            '',
            sprintf('🧭 HTF CONTEXT (%s + %s)', strtolower($entryTimeframe), strtolower($macroTimeframe)),
            sprintf('%s: %s', $this->escapeForHtml($macroTimeframe), $this->escapeForHtml((string) ($metadata['macro_context'] ?? 'N/A'))),
            sprintf('%s: %s', $this->escapeForHtml($structureTimeframe), $this->escapeForHtml((string) ($metadata['structure_context'] ?? 'N/A'))),
            sprintf('Zone (%s): %s', $this->escapeForHtml($structureTimeframe), $this->escapeForHtml((string) ($metadata['zone'] ?? 'N/A'))),
            sprintf('Strength: %s', $this->escapeForHtml((string) ($metadata['strength'] ?? '-'))),
            $this->escapeForHtml((string) ($metadata['trend_checks'] ?? 'Trend checks: N/A')),
            sprintf('Demand: %s', $this->escapeForHtml((string) ($metadata['demand_zone'] ?? 'N/A'))),
            sprintf('Supply: %s', $this->escapeForHtml((string) ($metadata['supply_zone'] ?? ($metadata['fvg_zone_15m'] ?? 'N/A')))),
            sprintf('✅ HTF Confluence: %s', $this->escapeForHtml((string) ($metadata['confluence'] ?? 'Not specified'))),
            '',
            sprintf('📊 MTF REGIME (%s)', strtolower($entryTimeframe)),
            sprintf('Regime: %s', $this->escapeForHtml((string) ($metadata['regime'] ?? 'N/A'))),
            sprintf(
                'ADX %s · ATR %s',
                $this->escapeForHtml((string) ($metadata['adx'] ?? '-')),
                $this->escapeForHtml((string) ($metadata['atr_percent'] ?? '-')),
            ),
            sprintf(
                'Strategi aktif: %s',
                $this->escapeForHtml((string) ($metadata['active_strategies'] ?? $strategy)),
            ),
            '',
            sprintf('🎯 LTF TRIGGERS (%s / %s)', strtolower($entryTimeframe), strtolower($structureTimeframe)),
            '',
            sprintf('📉 %s · %s', $this->escapeForHtml($direction), $this->escapeForHtml($strategy)),
            sprintf('👑 %s · Score %s', $this->escapeForHtml((string) ($metadata['tier'] ?? 'TIER -')), $this->escapeForHtml($score)),
            sprintf('Entry: %s', $this->escapeForHtml($entry)),
            sprintf('SL: %s · TP1: %s · TP2: %s', $this->escapeForHtml($stopLoss), $this->escapeForHtml($takeProfit), $this->escapeForHtml($takeProfit2)),
            sprintf('RR: %s', $this->escapeForHtml($riskReward)),
            sprintf('Factors: %s', $this->escapeForHtml($factors)),
            sprintf('Reason: %s', $this->escapeForHtml($reason)),
        ];

        if ($executionId !== null && $executionId !== '') {
            $lines[] = sprintf('Execution ID: %s', $this->escapeForHtml($executionId));
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<string, mixed>  $components
     */
    private function formatFactors(array $components): string
    {
        if ($components === []) {
            return '-';
        }

        $factors = [];

        foreach ($components as $key => $value) {
            if (is_bool($value)) {
                $formattedValue = $value ? 'yes' : 'no';
            } elseif (is_scalar($value)) {
                $formattedValue = (string) $value;
            } else {
                continue;
            }

            $factors[] = sprintf('%s=%s', $key, $formattedValue);
        }

        return $factors === [] ? '-' : implode(' · ', $factors);
    }

    /**
     * Format a price in multiple currencies.
     *
     * Displays the price in all configured display currencies.
     * Example: "$45,230.12 / Rp 720,000,000" (USD and IDR)
     *
     * @param  mixed  $value  The price value in USD (or base currency)
     * @param  string  $baseCurrency  The currency the value is in (default: 'USD')
     * @return string Formatted price with all display currencies
     */
    private function formatPriceMultiCurrency(mixed $value, string $baseCurrency = 'USD'): string
    {
        if (! is_numeric($value)) {
            return '-';
        }

        $baseCurrency = strtoupper($baseCurrency);
        $displayCurrencies = GeneralConfig::getDisplayCurrencies();

        // If no currencies configured or only base currency, use legacy format
        if ($displayCurrencies === [] || ($displayCurrencies === [$baseCurrency])) {
            return $this->formatPriceWithDollar($value);
        }

        $formattedParts = [];

        foreach ($displayCurrencies as $currency) {
            $formattedParts[] = $this->formatPriceInCurrency(
                (float) $value,
                $baseCurrency,
                $currency,
            );
        }

        return implode(' / ', array_filter($formattedParts));
    }

    /**
     * Format a price in a specific currency.
     *
     * @param  float  $price  The price in the base currency
     * @param  string  $fromCurrency  Source currency (e.g., 'USD')
     * @param  string  $toCurrency  Target currency (e.g., 'IDR')
     * @return string|null Formatted price with symbol, or null if conversion failed
     */
    private function formatPriceInCurrency(float $price, string $fromCurrency, string $toCurrency): ?string
    {
        $fromCurrency = strtoupper($fromCurrency);
        $toCurrency = strtoupper($toCurrency);

        // If same currency, format directly
        if ($fromCurrency === $toCurrency) {
            return $this->getCurrencySymbol($toCurrency).$this->formatDecimal((string) $price);
        }

        // Convert price to target currency
        $convertedPrice = $this->rateRepository->convertPrice($price, $fromCurrency, $toCurrency);

        if ($convertedPrice === null) {
            Log::warning("Cannot format price in {$toCurrency}: rate unavailable", [
                'from' => $fromCurrency,
                'to' => $toCurrency,
            ]);

            return null;
        }

        $decimals = $this->getDecimalPrecision($toCurrency);
        $formatted = number_format($convertedPrice, $decimals, '.', ',');

        return $this->getCurrencySymbol($toCurrency).$formatted;
    }

    /**
     * Get the currency symbol for formatting.
     */
    private function getCurrencySymbol(string $currency): string
    {
        return match (strtoupper($currency)) {
            'USD', 'USDT' => '$',
            'IDR' => 'Rp ',
            'EUR' => '€',
            'GBP' => '£',
            default => $currency.' ',
        };
    }

    /**
     * Get the decimal precision for a specific currency.
     *
     * IDR uses 0 decimals (Rp 720,000,000), USD uses 2 decimals typical.
     */
    private function getDecimalPrecision(string $currency): int
    {
        return match (strtoupper($currency)) {
            'IDR' => 0,
            'JPY', 'KRW' => 0,
            default => 2,
        };
    }

    private function formatPriceWithDollar(mixed $value): string
    {
        $formatted = $this->formatDecimal($value);

        return $formatted === null ? '-' : '$'.$formatted;
    }

    private function formatScore(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '-';
        }

        return sprintf('%s/100', rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.'));
    }

    /**
     * Send a generic system notification message.
     *
     * @param  array{
     *   execution_id?: string,
     *   title: string,
     *   lines?: array<int, string>
     * }  $payload
     */
    public function sendSystemMessage(array $payload): void
    {
        $title = (string) ($payload['title'] ?? 'System Notification');
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];

        Log::info('[NotificationService] System notification triggered', [
            'execution_id' => $payload['execution_id'] ?? null,
            'title' => $title,
            'lines' => $lines,
        ]);

        $message = $this->buildSystemMessage($title, $lines, $payload['execution_id'] ?? null);

        $this->sendTelegramMessage(
            message: $message,
            executionId: $payload['execution_id'] ?? null,
        );
    }

    /**
     * Send a trade signal notification for a strong BUY or SELL decision.
     *
     * Expects a decision array with keys: coin, timeframe, action, confidence,
     * reason, risk_level, entry (optional), take_profit (optional), stop_loss (optional).
     *
     * @param  array{
     *   execution_id?: string,
     *   coin: string,
     *   timeframe: string,
     *   action: string,
     *   confidence: int,
     *   reason: string,
     *   risk_level: string,
     *   entry?: float|null,
     *   take_profit?: float|null,
     *   stop_loss?: float|null,
     * }  $payload
     */
    public function sendTradeSignal(array $payload): void
    {
        $message = $this->buildTradeSignalMessage($payload);

        Log::info('[NotificationService] Trade signal triggered', [
            'execution_id' => $payload['execution_id'] ?? null,
            'coin' => $payload['coin'],
            'timeframe' => $payload['timeframe'],
            'action' => $payload['action'],
            'confidence' => $payload['confidence'],
            'risk_level' => $payload['risk_level'],
            'entry' => $payload['entry'] ?? null,
            'take_profit' => $payload['take_profit'] ?? null,
            'stop_loss' => $payload['stop_loss'] ?? null,
            'reason' => $payload['reason'],
        ]);

        $this->sendTelegramMessage(
            message: $message,
            executionId: $payload['execution_id'] ?? null,
        );
    }

    /**
     * Build a human-readable trade signal message for Telegram.
     *
     * @param  array{
     *   execution_id?: string,
     *   coin: string,
     *   timeframe: string,
     *   action: string,
     *   confidence: int,
     *   reason: string,
     *   risk_level: string,
     *   entry?: float|null,
     *   take_profit?: float|null,
     *   stop_loss?: float|null,
     * }  $payload
     */
    private function buildTradeSignalMessage(array $payload): string
    {
        $entry = $payload['entry'] ?? null;
        $takeProfit = $payload['take_profit'] ?? null;
        $stopLoss = $payload['stop_loss'] ?? null;
        $executionId = $payload['execution_id'] ?? '-';

        $entryFormatted = $entry !== null ? $this->formatPriceMultiCurrency($entry) : '-';
        $tpFormatted = $takeProfit !== null ? $this->formatPriceMultiCurrency($takeProfit) : '-';
        $slFormatted = $stopLoss !== null ? $this->formatPriceMultiCurrency($stopLoss) : '-';

        return implode(PHP_EOL, [
            '<b>AI Trading Signal</b>',
            sprintf('Coin: <b>%s</b>', $this->escapeForHtml((string) $payload['coin'])),
            sprintf('Timeframe: <b>%s</b>', $this->escapeForHtml((string) $payload['timeframe'])),
            sprintf('Action: <b>%s</b>', $this->escapeForHtml((string) $payload['action'])),
            sprintf('Confidence: <b>%d%%</b>', (int) $payload['confidence']),
            sprintf('Risk: <b>%s</b>', $this->escapeForHtml((string) $payload['risk_level'])),
            sprintf('Entry: <b>%s</b>', $entryFormatted),
            sprintf('TP: <b>%s</b>', $tpFormatted),
            sprintf('SL: <b>%s</b>', $slFormatted),
            sprintf('Reason: %s', $this->escapeForHtml((string) $payload['reason'])),
            sprintf('Execution ID: <code>%s</code>', $this->escapeForHtml((string) $executionId)),
        ]);
    }

    /**
     * @param  array<int, string>  $lines
     */
    private function buildSystemMessage(string $title, array $lines, ?string $executionId): string
    {
        $messageLines = [
            sprintf('<b>%s</b>', $this->escapeForHtml($title)),
        ];

        foreach ($lines as $line) {
            $messageLines[] = $this->escapeForHtml((string) $line);
        }

        if ($executionId !== null && $executionId !== '') {
            $messageLines[] = sprintf('Execution ID: <code>%s</code>', $this->escapeForHtml($executionId));
        }

        return implode(PHP_EOL, $messageLines);
    }

    /**
     * @param  array<int, array<string, mixed>>  $detailRows
     */
    private function buildPassedCoinsSummary(array $detailRows): string
    {
        $coins = [];

        foreach ($detailRows as $row) {
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));

            if ($symbol === '') {
                continue;
            }

            $coins[] = $symbol;
        }

        $coins = array_values(array_unique($coins));

        return $coins === [] ? '-' : implode(', ', $coins);
    }

    /**
     * Send a prepared message to Telegram when bot token and chat id are configured.
     */
    private function sendTelegramMessage(string $message, ?string $executionId): void
    {
        $chatId = (string) config('services.telegram.chat_id', '');

        if ($chatId === '') {
            Log::warning('[NotificationService] Telegram chat ID is not configured, skipping send', [
                'execution_id' => $executionId,
            ]);

            return;
        }

        $botName = (string) config('services.telegram.bot', 'mybot');

        try {
            Telegram::bot($botName)->sendMessage([
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            Log::info('[NotificationService] Telegram message sent', [
                'execution_id' => $executionId,
                'bot' => $botName,
                'chat_id' => $chatId,
            ]);
        } catch (Throwable $exception) {
            Log::error('[NotificationService] Telegram send failed', [
                'execution_id' => $executionId,
                'bot' => $botName,
                'chat_id' => $chatId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Format optional numeric values with a stable decimal representation.
     */
    private function formatDecimal(mixed $value): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 8, '.', '');
    }

    /**
     * Escape dynamic values to keep Telegram HTML parse mode safe.
     */
    private function escapeForHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function formatModelName(string $value): string
    {
        return (string) Str::of($value)
            ->replace('_', ' ')
            ->title();
    }
}
