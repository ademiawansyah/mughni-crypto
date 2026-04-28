<?php

namespace App\Filament\Widgets;

use App\Models\AiDecision;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class HighestConvictionSignalsWidget extends TableWidget
{
    protected static ?string $heading = 'Highest Conviction Signals (>= 85%)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->highConvictionQuery())
            ->defaultSort('confidence', 'desc')
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('model')
                    ->label('Model')
                    ->badge()
                    ->formatStateUsing(fn(?string $state): string => match ($state) {
                        'counter_trend' => 'Counter-Trend',
                        'pre_pump' => 'Pre-Pump',
                        'momentum' => 'Momentum',
                        default => 'Unknown',
                    }),

                TextColumn::make('coin')
                    ->searchable(),

                TextColumn::make('action')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'BUY' => 'success',
                        'SELL' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('confidence')
                    ->suffix('%')
                    ->sortable(),

                TextColumn::make('risk_level')
                    ->label('Risk')
                    ->badge(),

                TextColumn::make('timestamp')
                    ->label('Generated')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->striped();
    }

    protected function highConvictionQuery(): Builder
    {
        return AiDecision::query()
            ->whereNotNull('model')
            ->whereIn('action', ['BUY', 'SELL'])
            ->where('confidence', '>=', 85)
            ->orderByDesc('timestamp');
    }
}
