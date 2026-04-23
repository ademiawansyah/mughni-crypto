<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AiDecisionResource\Pages;
use App\Models\AiDecision;
use App\Models\GeneralConfig;
use App\Models\MarketContext;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
    protected static ?string $model = AiDecision::class;

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

        $latestDecisionIds = AiDecision::query()
            ->whereIn('coin', $watchlist)
            ->selectRaw('DISTINCT ON (coin) id')
            ->orderBy('coin')
            ->orderByDesc('created_at');

        return $query
            ->whereIn('id', $latestDecisionIds)
            ->orderByDesc('created_at');
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

                TextColumn::make('price_at_decision')
                    ->label('Price')
                    ->numeric(decimalPlaces: 4)
                    ->sortable(),

                TextColumn::make('rsi')
                    ->label('RSI')
                    ->state(fn(AiDecision $record): string => static::resolveRsi($record))
                    ->color(fn(AiDecision $record): string => static::rsiColor($record))
                    ->sortable(
                        query: fn(Builder $query, string $direction) => $query
                            ->leftJoin('market_indicators as mi_rsi', function ($join) {
                                $join->on('ai_decisions.coin', '=', 'mi_rsi.coin')
                                    ->on('ai_decisions.timeframe', '=', 'mi_rsi.timeframe')
                                    ->on('ai_decisions.timestamp', '=', 'mi_rsi.timestamp');
                            })
                            ->orderBy('mi_rsi.rsi', $direction)
                    ),

                BadgeColumn::make('trend')
                    ->label('Trend')
                    ->state(fn(AiDecision $record): string => static::resolveTrend($record))
                    ->colors([
                        'success' => 'UP',
                        'danger' => 'DOWN',
                        'secondary' => 'NEUTRAL',
                    ]),

                BadgeColumn::make('action')
                    ->colors([
                        'success' => 'BUY',
                        'danger' => 'SELL',
                        'warning' => 'HOLD',
                    ])
                    ->sortable(),

                TextColumn::make('confidence')
                    ->label('Conf %')
                    ->suffix('%')
                    ->sortable(),

                TextColumn::make('risk_level')
                    ->label('Risk')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'LOW' => 'success',
                        'MEDIUM' => 'warning',
                        default => 'danger',
                    }),

                BadgeColumn::make('result')
                    ->colors([
                        'success' => 'win',
                        'danger' => 'loss',
                        'secondary' => fn($state) => $state === null,
                    ])
                    ->formatStateUsing(fn(?string $state): string => $state ?? 'pending'),

                BadgeColumn::make('mtf_mode')
                    ->label('MTF Mode')
                    ->state(fn(AiDecision $record): string => static::resolveMtfMode($record))
                    ->colors([
                        'info' => 'trend_follow',
                        'warning' => 'reversal',
                        'secondary' => 'n/a',
                    ]),

                BadgeColumn::make('mtf_alignment')
                    ->label('MTF Align')
                    ->state(fn(AiDecision $record): string => static::resolveMtfAlignment($record))
                    ->colors([
                        'success' => 'aligned',
                        'warning' => 'mixed',
                        'danger' => 'conflict',
                        'secondary' => 'n/a',
                    ]),

                TextColumn::make('price_after_5m')
                    ->label('+5m')
                    ->numeric(decimalPlaces: 4)
                    ->placeholder('—'),

                TextColumn::make('price_after_15m')
                    ->label('+15m')
                    ->numeric(decimalPlaces: 4)
                    ->placeholder('—'),

                TextColumn::make('max_profit')
                    ->label('Max Profit')
                    ->numeric(decimalPlaces: 4)
                    ->placeholder('—'),

                TextColumn::make('max_drawdown')
                    ->label('Max DD')
                    ->numeric(decimalPlaces: 4)
                    ->placeholder('—'),

                TextColumn::make('timestamp')
                    ->label('At')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('action')
                    ->options([
                        'BUY' => 'BUY',
                        'SELL' => 'SELL',
                        'HOLD' => 'HOLD',
                    ]),

                SelectFilter::make('result')
                    ->options([
                        'win' => 'Win',
                        'loss' => 'Loss',
                    ]),

                SelectFilter::make('risk_level')
                    ->label('Risk Level')
                    ->options([
                        'LOW' => 'Low',
                        'MEDIUM' => 'Medium',
                        'HIGH' => 'High',
                    ]),
            ])
            ->actions([
                ViewAction::make(),
            ])
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
            'view' => Pages\ViewAiDecision::route('/{record}'),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers — resolve joined data without N+1 by using raw input_data cache
    // -------------------------------------------------------------------------

    private static function resolveRsi(AiDecision $record): string
    {
        $inputData = $record->input_data;
        if (is_array($inputData) && isset($inputData['rsi'])) {
            return number_format((float) $inputData['rsi'], 2);
        }

        return '—';
    }

    private static function rsiColor(AiDecision $record): string
    {
        $inputData = $record->input_data;
        if (! is_array($inputData) || ! isset($inputData['rsi'])) {
            return 'secondary';
        }
        $rsi = (float) $inputData['rsi'];

        if ($rsi >= 70) {
            return 'danger';
        }
        if ($rsi <= 30) {
            return 'success';
        }

        return 'secondary';
    }

    private static function resolveTrend(AiDecision $record): string
    {
        $inputData = $record->input_data;
        if (is_array($inputData) && isset($inputData['ema9'], $inputData['ema21'])) {
            return (float) $inputData['ema9'] > (float) $inputData['ema21'] ? 'UP' : 'DOWN';
        }

        if (is_array($inputData) && isset($inputData['trend'])) {
            return strtoupper((string) $inputData['trend']);
        }

        return 'NEUTRAL';
    }

    private static function resolveMtfMode(AiDecision $record): string
    {
        $context = static::latestMtfContextByCoin()[$record->coin] ?? null;

        return $context['market_regime'] ?? 'n/a';
    }

    private static function resolveMtfAlignment(AiDecision $record): string
    {
        $context = static::latestMtfContextByCoin()[$record->coin] ?? null;

        return $context['sentiment'] ?? 'n/a';
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
}
