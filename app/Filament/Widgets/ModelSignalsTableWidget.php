<?php

namespace App\Filament\Widgets;

use App\Models\AiDecision;
use Filament\Tables\Columns\BadgeColumn;
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

                TextColumn::make('total_score')
                    ->label('Total Score')
                    ->state(function (AiDecision $record): ?int {
                        $input = is_array($record->input_data) ? $record->input_data : [];
                        $signal = is_array($input['signal'] ?? null) ? $input['signal'] : [];

                        $score = $signal['total_score'] ?? $signal['score'] ?? null;

                        return is_numeric($score) ? (int) round((float) $score) : null;
                    })
                    ->sortable(),

                TextColumn::make('action')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
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
                    ->color(fn (?string $state): string => match ($state) {
                        'LOW' => 'success',
                        'MEDIUM' => 'warning',
                        'HIGH' => 'danger',
                        default => 'gray',
                    }),

                BadgeColumn::make('components')
                    ->label('Top Components')
                    ->separator(' | ')
                    ->state(function (AiDecision $record): array {
                        $input = is_array($record->input_data) ? $record->input_data : [];
                        $components = is_array($input['component_scores'] ?? null)
                            ? $input['component_scores']
                            : [];

                        if ($components === []) {
                            return [];
                        }

                        arsort($components);

                        return collect($components)
                            ->take(3)
                            ->map(function (mixed $value, string $name): string {
                                $score = is_numeric($value)
                                    ? (int) round(((float) $value) * 100)
                                    : 0;

                                return sprintf('%s:%d%%', strtoupper($name), $score);
                            })
                            ->values()
                            ->all();
                    })
                    ->colors([
                        'gray',
                    ]),

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
