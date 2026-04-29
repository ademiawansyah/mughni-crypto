<?php

namespace App\Filament\Widgets;

use App\Models\AiDecision;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ConsensusCoinsWidget extends TableWidget
{
    protected static ?string $heading = 'Consensus Coins (Multiple Models)';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->consensusQuery())
            ->defaultSort('model_count', 'desc')
            ->defaultKeySort(false)
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('coin')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('model_count')
                    ->label('Models')
                    ->badge()
                    ->color(fn (int $state): string => $state >= 3 ? 'success' : 'warning')
                    ->sortable(),

                TextColumn::make('max_confidence')
                    ->label('Top Confidence')
                    ->suffix('%')
                    ->sortable(),

                TextColumn::make('last_seen_at')
                    ->label('Last Seen')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->striped();
    }

    protected function consensusQuery(): Builder
    {
        return AiDecision::query()
            ->selectRaw('coin, COUNT(DISTINCT model) as model_count, MAX(confidence) as max_confidence, MAX(timestamp) as last_seen_at')
            ->whereNotNull('model')
            ->where('timestamp', '>=', now()->subDay())
            ->groupBy('coin')
            ->havingRaw('COUNT(DISTINCT model) >= 2');
    }
}
