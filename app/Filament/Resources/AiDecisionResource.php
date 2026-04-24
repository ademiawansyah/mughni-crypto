<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiDecisionResource\Pages;
use App\Models\AiDecision;
use App\Models\GeneralConfig;
use App\Models\MarketContext;
use App\Models\MarketIndicator;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * AiDecisionResource
 *
 * Read-only Filament resource providing visibility into AI trading decisions,
 * MTF context, and market indicators in a single dashboard view.
 */
class AiDecisionResource extends Resource
{
    protected static ?string $model = MarketContext::class;

    protected static \BackedEnum|string|null $navigationIcon = Heroicon::ChartBar;

    protected static ?string $navigationLabel = 'AI Decisions';

    protected static ?string $modelLabel = 'AI Decision';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        $watchlist = GeneralConfig::getWatchlistCoins();

        $query = parent::getEloquentQuery();

        if ($watchlist === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn('coin', $watchlist)
            ->where('timeframe', 'summary')
            ->whereIn('source', ['mtf', 'mtf_service'])
            ->orderByDesc('timestamp');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('coin')
                    ->sortable()
                    ->searchable()
                    ->weight('bold'),

                TextColumn::make('price')
                    ->label('Price')
                    ->state(fn(MarketContext $record): ?float => static::resolvePrice($record))
                    ->numeric(decimalPlaces: 4)
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('rsi_display')
                    ->label('RSI')
                    ->state(fn(MarketContext $record): string => static::resolveRsi($record))
                    ->color(fn(MarketContext $record): string => static::rsiColor($record)),

                BadgeColumn::make('trend_display')
                    ->label('Trend')
                    ->state(fn(MarketContext $record): string => static::resolveTrend($record))
                    ->colors([
                        'success' => 'UP',
                        'danger' => 'DOWN',
                        'secondary' => 'NEUTRAL',
                    ]),

                BadgeColumn::make('mcp_passed')
                    ->label('MCP')
                    ->state(fn(MarketContext $record): string => $record->mcp_passed ? 'PASS' : 'FAIL')
                    ->colors([
                        'success' => 'PASS',
                        'danger' => 'FAIL',
                    ]),

                TextColumn::make('mcp_score')
                    ->label('MCP Score')
                    ->placeholder('—')
                    ->sortable(),

                BadgeColumn::make('mcp_candidate')
                    ->label('MCP Candidate')
                    ->formatStateUsing(fn(?string $state): string => $state ?? 'NONE')
                    ->colors([
                        'success' => 'BUY',
                        'danger' => 'SELL',
                        'warning' => 'HOLD',
                        'secondary' => 'NONE',
                    ])
                    ->sortable(),

                BadgeColumn::make('preliminary_action')
                    ->label('MTF Decision')
                    ->formatStateUsing(fn(?string $state): string => $state ?? 'HOLD')
                    ->colors([
                        'success' => 'BUY',
                        'danger' => 'SELL',
                        'warning' => 'HOLD',
                    ]),

                TextColumn::make('mtf_score')
                    ->label('MTF Score')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—')
                    ->sortable(),

                BadgeColumn::make('fusion_final_action')
                    ->label('Fusion')
                    ->formatStateUsing(fn(?string $state): string => $state ?? 'N/A')
                    ->colors([
                        'success' => 'BUY',
                        'danger' => 'SELL',
                        'warning' => 'HOLD',
                        'secondary' => 'N/A',
                    ]),

                BadgeColumn::make('action_display')
                    ->label('Action')
                    ->state(fn(MarketContext $record): string => static::resolveAction($record))
                    ->colors([
                        'success' => 'BUY',
                        'danger' => 'SELL',
                        'warning' => 'HOLD',
                        'secondary' => 'N/A',
                    ]),

                TextColumn::make('confidence_display')
                    ->label('Conf %')
                    ->state(fn(MarketContext $record): string => static::resolveConfidence($record))
                    ->suffix('%')
                    ->sortable(false),

                TextColumn::make('risk_display')
                    ->label('Risk')
                    ->state(fn(MarketContext $record): string => static::resolveRisk($record))
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'LOW' => 'success',
                        'MEDIUM' => 'warning',
                        'N/A' => 'secondary',
                        default => 'danger',
                    }),

                BadgeColumn::make('result_display')
                    ->label('Result')
                    ->state(fn(MarketContext $record): ?string => static::resolveResult($record))
                    ->colors([
                        'success' => 'win',
                        'danger' => 'loss',
                        'secondary' => fn($state) => $state === null,
                    ])
                    ->formatStateUsing(fn(?string $state): string => $state ?? 'pending'),

                BadgeColumn::make('mtf_mode')
                    ->label('MTF Mode')
                    ->state(fn(MarketContext $record): string => static::resolveMtfMode($record))
                    ->colors([
                        'info' => 'trend_follow',
                        'warning' => 'reversal',
                        'secondary' => 'n/a',
                    ]),

                BadgeColumn::make('mtf_alignment')
                    ->label('MTF Align')
                    ->state(fn(MarketContext $record): string => static::resolveMtfAlignment($record))
                    ->colors([
                        'success' => 'aligned',
                        'warning' => 'mixed',
                        'danger' => 'conflict',
                        'secondary' => 'n/a',
                    ]),

                TextColumn::make('price_after_5m')
                    ->label('+5m')
                    ->state(fn(MarketContext $record): ?string => static::resolvePriceAfter($record, 'price_after_5m'))
                    ->numeric(decimalPlaces: 4)
                    ->placeholder('—'),

                TextColumn::make('price_after_15m')
                    ->label('+15m')
                    ->state(fn(MarketContext $record): ?string => static::resolvePriceAfter($record, 'price_after_15m'))
                    ->numeric(decimalPlaces: 4)
                    ->placeholder('—'),

                TextColumn::make('max_profit')
                    ->label('Max Profit')
                    ->state(fn(MarketContext $record): ?string => static::resolvePriceAfter($record, 'max_profit'))
                    ->numeric(decimalPlaces: 4)
                    ->placeholder('—'),

                TextColumn::make('max_drawdown')
                    ->label('Max DD')
                    ->state(fn(MarketContext $record): ?string => static::resolvePriceAfter($record, 'max_drawdown'))
                    ->numeric(decimalPlaces: 4)
                    ->placeholder('—'),

                TextColumn::make('timestamp')
                    ->label('At')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([])
            ->actions([])
            ->bulkActions([])
            ->striped();
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAiDecisions::route('/'),
        ];
    }

    private static function resolvePrice(MarketContext $record): ?float
    {
        $decision = static::latestDecisionByCoin()[$record->coin] ?? null;

        if ($decision !== null && is_numeric($decision->price_at_decision)) {
            return (float) $decision->price_at_decision;
        }

        $indicator = static::latestIndicatorByCoin()[$record->coin] ?? null;

        if ($indicator !== null && is_numeric($indicator->price)) {
            return (float) $indicator->price;
        }

        return null;
    }

    private static function resolveRsi(MarketContext $record): string
    {
        $decision = static::latestDecisionByCoin()[$record->coin] ?? null;
        $inputData = $decision?->input_data;

        if (is_array($inputData) && isset($inputData['rsi'])) {
            return number_format((float) $inputData['rsi'], 2);
        }

        $indicator = static::latestIndicatorByCoin()[$record->coin] ?? null;

        if ($indicator !== null && is_numeric($indicator->rsi)) {
            return number_format((float) $indicator->rsi, 2);
        }

        return '—';
    }

    private static function rsiColor(MarketContext $record): string
    {
        $rsi = static::resolveRsiNumeric($record);

        if ($rsi === null) {
            return 'secondary';
        }

        if ($rsi >= 70) {
            return 'danger';
        }
        if ($rsi <= 30) {
            return 'success';
        }

        return 'secondary';
    }

    private static function resolveTrend(MarketContext $record): string
    {
        $decision = static::latestDecisionByCoin()[$record->coin] ?? null;
        $inputData = $decision?->input_data;

        if (is_array($inputData) && isset($inputData['ema9'], $inputData['ema21'])) {
            return (float) $inputData['ema9'] > (float) $inputData['ema21'] ? 'UP' : 'DOWN';
        }

        if (is_array($inputData) && isset($inputData['trend'])) {
            return strtoupper((string) $inputData['trend']);
        }

        $indicator = static::latestIndicatorByCoin()[$record->coin] ?? null;

        if ($indicator !== null && is_string($indicator->trend)) {
            $trend = strtoupper(trim($indicator->trend));

            return match ($trend) {
                'UPTREND', 'UP' => 'UP',
                'DOWNTREND', 'DOWN' => 'DOWN',
                default => 'NEUTRAL',
            };
        }

        return 'NEUTRAL';
    }

    private static function resolveAction(MarketContext $record): string
    {
        $decision = static::latestDecisionByCoin()[$record->coin] ?? null;

        if ($decision !== null && is_string($decision->action)) {
            return strtoupper($decision->action);
        }

        if (is_string($record->final_action) && $record->final_action !== '') {
            return strtoupper($record->final_action);
        }

        return 'HOLD';
    }

    private static function resolveConfidence(MarketContext $record): string
    {
        $decision = static::latestDecisionByCoin()[$record->coin] ?? null;

        if ($decision !== null) {
            return (string) ((int) $decision->confidence);
        }

        if ($record->final_confidence !== null) {
            return (string) ((int) $record->final_confidence);
        }

        return '0';
    }

    private static function resolveRisk(MarketContext $record): string
    {
        $decision = static::latestDecisionByCoin()[$record->coin] ?? null;

        if ($decision !== null && is_string($decision->risk_level)) {
            return strtoupper($decision->risk_level);
        }

        return 'N/A';
    }

    private static function resolveResult(MarketContext $record): ?string
    {
        $decision = static::latestDecisionByCoin()[$record->coin] ?? null;

        return $decision?->result;
    }

    private static function resolveMtfMode(MarketContext $record): string
    {
        $context = static::latestMtfContextByCoin()[$record->coin] ?? null;

        return $context['market_regime'] ?? 'n/a';
    }

    private static function resolveMtfAlignment(MarketContext $record): string
    {
        $context = static::latestMtfContextByCoin()[$record->coin] ?? null;

        return $context['sentiment'] ?? 'n/a';
    }

    private static function resolvePriceAfter(MarketContext $record, string $key): ?string
    {
        $decision = static::latestDecisionByCoin()[$record->coin] ?? null;

        if ($decision === null) {
            return null;
        }

        $value = $decision->{$key};

        return is_numeric($value) ? (string) $value : null;
    }

    /**
     * Resolve latest MTF context rows once per request and key them by coin.
     *
     * @return array<string, array{market_regime: string|null, sentiment: string|null}>
     */
    private static function latestMtfContextByCoin(): array
    {
        return once(function (): array {
            $watchlist = GeneralConfig::getWatchlistCoins();

            if ($watchlist === []) {
                return [];
            }

            return MarketContext::query()
                ->whereIn('coin', $watchlist)
                ->whereIn('source', ['mtf', 'mtf_service'])
                ->selectRaw('DISTINCT ON (coin) coin, market_regime, sentiment')
                ->orderBy('coin')
                ->orderByDesc('timestamp')
                ->get()
                ->mapWithKeys(fn(MarketContext $context): array => [
                    $context->coin => [
                        'market_regime' => $context->market_regime,
                        'sentiment' => $context->sentiment,
                    ],
                ])
                ->all();
        });
    }

    /**
     * @return array<string, AiDecision>
     */
    private static function latestDecisionByCoin(): array
    {
        return once(function (): array {
            $watchlist = GeneralConfig::getWatchlistCoins();

            if ($watchlist === []) {
                return [];
            }

            return AiDecision::query()
                ->whereIn('coin', $watchlist)
                ->selectRaw('DISTINCT ON (coin) *')
                ->orderBy('coin')
                ->orderByDesc('timestamp')
                ->get()
                ->mapWithKeys(fn(AiDecision $decision): array => [$decision->coin => $decision])
                ->all();
        });
    }

    /**
     * @return array<string, MarketIndicator>
     */
    private static function latestIndicatorByCoin(): array
    {
        return once(function (): array {
            $watchlist = GeneralConfig::getWatchlistCoins();

            if ($watchlist === []) {
                return [];
            }

            $configuredTimeframes = GeneralConfig::getTimeframes();
            $displayTimeframe = $configuredTimeframes[0] ?? '5m';

            return MarketIndicator::query()
                ->whereIn('coin', $watchlist)
                ->where('timeframe', $displayTimeframe)
                ->selectRaw('DISTINCT ON (coin) *')
                ->orderBy('coin')
                ->orderByDesc('timestamp')
                ->get()
                ->mapWithKeys(fn(MarketIndicator $indicator): array => [$indicator->coin => $indicator])
                ->all();
        });
    }

    private static function resolveRsiNumeric(MarketContext $record): ?float
    {
        $decision = static::latestDecisionByCoin()[$record->coin] ?? null;
        $inputData = $decision?->input_data;

        if (is_array($inputData) && isset($inputData['rsi']) && is_numeric($inputData['rsi'])) {
            return (float) $inputData['rsi'];
        }

        $indicator = static::latestIndicatorByCoin()[$record->coin] ?? null;

        if ($indicator !== null && is_numeric($indicator->rsi)) {
            return (float) $indicator->rsi;
        }

        return null;
    }
}
