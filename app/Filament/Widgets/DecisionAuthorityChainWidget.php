<?php

namespace App\Filament\Widgets;

use App\Models\MarketContext;
use App\Services\Trading\SignalQueryService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * DecisionAuthorityChainWidget
 *
 * Tabular chain view from MCP gate output through final authority decision.
 */
class DecisionAuthorityChainWidget extends TableWidget
{
    protected static ?string $heading = 'Decision Authority Chain';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->query())
            ->defaultSort('timestamp', 'desc')
            ->paginated([10])
            ->defaultPaginationPageOption(10)
            ->columns([
                TextColumn::make('execution_id')
                    ->label('Execution')
                    ->state(fn (MarketContext $record): string => substr((string) $record->execution_id, 0, 8)),

                TextColumn::make('coin')
                    ->searchable(),

                TextColumn::make('mcp_candidate')
                    ->label('MCP')
                    ->state(fn (MarketContext $record): string => strtoupper((string) ($record->mcp_candidate ?? 'NONE')))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'BUY' => 'success',
                        'SELL' => 'danger',
                        'HOLD' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('preliminary_action')
                    ->label('Advisory')
                    ->state(fn (MarketContext $record): string => strtoupper((string) ($record->preliminary_action ?? 'HOLD')))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'BUY' => 'success',
                        'SELL' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('guardrail_outcome')
                    ->label('Guardrail')
                    ->state(function (MarketContext $record): string {
                        $status = strtolower((string) ($record->decision_status ?? 'rejected'));

                        return $status === 'accepted' ? 'PASSED' : 'BLOCKED';
                    })
                    ->badge()
                    ->color(fn (string $state): string => $state === 'PASSED' ? 'success' : 'danger'),

                TextColumn::make('final_action')
                    ->label('Final')
                    ->state(fn (MarketContext $record): string => strtoupper((string) ($record->final_action ?? 'HOLD')))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'BUY' => 'success',
                        'SELL' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('decision_status')
                    ->label('Status')
                    ->state(fn (MarketContext $record): string => strtoupper((string) ($record->decision_status ?? 'rejected')))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'ACCEPTED' ? 'success' : 'danger'),

                TextColumn::make('timestamp')
                    ->label('At')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->striped();
    }

    private function query(): Builder
    {
        /** @var SignalQueryService $service */
        $service = app(SignalQueryService::class);

        return $service->decisionAuthorityChainQuery();
    }
}
