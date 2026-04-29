<?php

namespace App\Filament\Widgets;

use App\Models\AiDecision;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ModelExecutionHistoryWidget extends TableWidget
{
    protected static ?string $heading = 'Execution History by Model';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->executionHistoryQuery())
            ->defaultSort('executed_at', 'desc')
            ->defaultKeySort(false)
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('execution_id')
                    ->label('Execution')
                    ->state(fn ($record): string => substr((string) $record->execution_id, 0, 8)),

                TextColumn::make('model')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'counter_trend' => 'Counter-Trend',
                        'pre_pump' => 'Pre-Pump',
                        'momentum' => 'Momentum',
                        default => 'Unknown',
                    }),

                TextColumn::make('signals_count')
                    ->label('Signals')
                    ->badge()
                    ->sortable(),

                TextColumn::make('top_confidence')
                    ->label('Top Confidence')
                    ->suffix('%')
                    ->sortable(),

                TextColumn::make('executed_at')
                    ->label('Executed At')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->striped();
    }

    protected function executionHistoryQuery(): Builder
    {
        return AiDecision::query()
            ->selectRaw('MIN(id) as id, execution_id, model, COUNT(*) as signals_count, MAX(confidence) as top_confidence, MAX(timestamp) as executed_at')
            ->whereNotNull('execution_id')
            ->whereNotNull('model')
            ->groupBy('execution_id', 'model');
    }
}
