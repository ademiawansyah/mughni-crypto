<?php

namespace App\Filament\Resources\AiDecisionResource\Pages;

use App\Filament\Resources\AiDecisionResource;
use App\Models\AiDecision;
use App\Models\MarketContext;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * ViewAiDecision
 *
 * Detail page split into four sections:
 *   A — Signal     — action, confidence, risk, price
 *   B — Outcome    — post-decision prices, profit/drawdown, result
 *   C — Context    — MTF mode and alignment
 *   D — AI Debug   — raw response JSON
 */
class ViewAiDecision extends ViewRecord
{
    protected static string $resource = AiDecisionResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                // ── Section A — Signal ──────────────────────────────────────
                Section::make('Signal')
                    ->schema([
                        Grid::make(4)
                            ->schema([
                                TextEntry::make('action')
                                    ->badge()
                                    ->color(fn(string $state): string => match ($state) {
                                        'BUY' => 'success',
                                        'SELL' => 'danger',
                                        default => 'warning',
                                    }),

                                TextEntry::make('confidence')
                                    ->suffix('%')
                                    ->label('Confidence'),

                                TextEntry::make('risk_level')
                                    ->label('Risk Level')
                                    ->badge()
                                    ->color(fn(string $state): string => match ($state) {
                                        'LOW' => 'success',
                                        'MEDIUM' => 'warning',
                                        default => 'danger',
                                    }),

                                TextEntry::make('price_at_decision')
                                    ->label('Price at Decision')
                                    ->numeric(decimalPlaces: 4),
                            ]),
                    ]),

                // ── Section B — Outcome ─────────────────────────────────────
                Section::make('Outcome')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                TextEntry::make('price_after_5m')
                                    ->label('Price +5m')
                                    ->numeric(decimalPlaces: 4)
                                    ->placeholder('—'),

                                TextEntry::make('price_after_15m')
                                    ->label('Price +15m')
                                    ->numeric(decimalPlaces: 4)
                                    ->placeholder('—'),

                                TextEntry::make('price_after_1h')
                                    ->label('Price +1h')
                                    ->numeric(decimalPlaces: 4)
                                    ->placeholder('—'),

                                TextEntry::make('max_profit')
                                    ->label('Max Profit')
                                    ->numeric(decimalPlaces: 4)
                                    ->placeholder('—'),

                                TextEntry::make('max_drawdown')
                                    ->label('Max Drawdown')
                                    ->numeric(decimalPlaces: 4)
                                    ->placeholder('—'),

                                TextEntry::make('result')
                                    ->badge()
                                    ->color(fn(?string $state): string => match ($state) {
                                        'win' => 'success',
                                        'loss' => 'danger',
                                        default => 'secondary',
                                    })
                                    ->formatStateUsing(fn(?string $state): string => $state ?? 'pending'),
                            ]),
                    ]),

                // ── Section C — Context (MTF) ───────────────────────────────
                Section::make('MTF Context')
                    ->schema([
                        Grid::make(2)
                            ->schema([
                                TextEntry::make('mtf_mode')
                                    ->label('MTF Mode')
                                    ->badge()
                                    ->state(function (AiDecision $record): string {
                                        return MarketContext::query()
                                            ->where('coin', $record->coin)
                                            ->whereIn('source', ['mtf', 'mtf_service'])
                                            ->orderByDesc('timestamp')
                                            ->value('market_regime') ?? 'n/a';
                                    })
                                    ->color(fn(string $state): string => match ($state) {
                                        'trend_follow' => 'info',
                                        'reversal' => 'warning',
                                        default => 'secondary',
                                    }),

                                TextEntry::make('mtf_alignment')
                                    ->label('MTF Alignment')
                                    ->badge()
                                    ->state(function (AiDecision $record): string {
                                        return MarketContext::query()
                                            ->where('coin', $record->coin)
                                            ->whereIn('source', ['mtf', 'mtf_service'])
                                            ->orderByDesc('timestamp')
                                            ->value('sentiment') ?? 'n/a';
                                    })
                                    ->color(fn(string $state): string => match ($state) {
                                        'aligned' => 'success',
                                        'conflict' => 'danger',
                                        'mixed' => 'warning',
                                        default => 'secondary',
                                    }),
                            ]),
                    ]),

                // ── Section D — AI Debug ────────────────────────────────────
                Section::make('AI Debug')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('raw_response')
                            ->label('Raw AI Response')
                            ->formatStateUsing(fn(mixed $state): string => is_array($state)
                                ? json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                                : (string) ($state ?? '—'))
                            ->html()
                            ->columnSpanFull()
                            ->extraAttributes(['class' => 'font-mono text-xs whitespace-pre-wrap']),

                        TextEntry::make('model_used')
                            ->label('Model'),

                        TextEntry::make('latency_ms')
                            ->label('Latency (ms)'),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [];
    }
}
