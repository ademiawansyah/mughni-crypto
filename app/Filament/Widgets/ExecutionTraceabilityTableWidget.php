<?php

namespace App\Filament\Widgets;

use App\Models\AiDecision;
use App\Services\Trading\SignalQueryService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * ExecutionTraceabilityTableWidget
 *
 * Execution-centric traceability table keyed by execution_id.
 */
class ExecutionTraceabilityTableWidget extends TableWidget
{
    protected static ?string $heading = 'Execution Traceability';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->query())
            ->defaultSort('executed_at', 'desc')
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('execution_id')
                    ->label('Execution ID')
                    ->state(fn (AiDecision $record): string => (string) $record->execution_id)
                    ->searchable(),

                TextColumn::make('model_count')
                    ->label('Models')
                    ->sortable(),

                TextColumn::make('signal_count')
                    ->label('Signals')
                    ->sortable(),

                TextColumn::make('actionable_count')
                    ->label('BUY/SELL')
                    ->sortable(),

                TextColumn::make('executed_at')
                    ->label('Latest At')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->striped();
    }

    private function query(): Builder
    {
        /** @var SignalQueryService $service */
        $service = app(SignalQueryService::class);

        return $service->executionTraceabilityQuery();
    }
}
