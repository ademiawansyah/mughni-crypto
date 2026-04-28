<?php

namespace App\Filament\Widgets;

use App\Models\AiDecision;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

abstract class ModelSignalsTableWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    abstract protected function modelKey(): string;

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery())
            ->defaultSort('timestamp', 'desc')
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('coin')
                    ->label('Coin')
                    ->searchable(),

                TextColumn::make('action')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'BUY' => 'success',
                        'SELL' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('confidence')
                    ->label('Confidence')
                    ->suffix('%')
                    ->sortable(),

                TextColumn::make('timeframe')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('risk_level')
                    ->label('Risk')
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        'LOW' => 'success',
                        'MEDIUM' => 'warning',
                        'HIGH' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('timestamp')
                    ->label('Generated')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->striped();
    }

    protected function baseQuery(): Builder
    {
        return AiDecision::query()
            ->where('model', $this->modelKey())
            ->whereIn('action', ['BUY', 'SELL', 'HOLD']);
    }
}
