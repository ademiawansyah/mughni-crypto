<?php

namespace App\Filament\Resources\ModelScanResults\Widgets;

use App\Models\ModelScanResult;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class FailedCoinsTableWidget extends TableWidget
{
    protected static bool $isLazy = false;

    public ModelScanResult $record;

    public function mount(ModelScanResult $record): void
    {
        $this->record = $record;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Failed Coins')
            ->description('Loaded from model_scan_result_details')
            ->query(fn () => $this->record->details()->with('coin')->orderBy('rank'))
            ->queryStringIdentifier('failedCoins')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10])
            ->paginated([10])
            ->columns([
                TextColumn::make('rank')
                    ->extraHeaderAttributes(['class' => 'w-16'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap']),
                TextColumn::make('coin.symbol')
                    ->label('Coin')
                    ->extraHeaderAttributes(['class' => 'w-28'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap'])
                    ->placeholder('-'),
                TextColumn::make('price')
                    ->numeric(decimalPlaces: 8)
                    ->extraHeaderAttributes(['class' => 'w-36'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap'])
                    ->placeholder('-'),
                TextColumn::make('stop_loss')
                    ->label('Stop Loss')
                    ->numeric(decimalPlaces: 8)
                    ->extraHeaderAttributes(['class' => 'w-36'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap'])
                    ->placeholder('-'),
                TextColumn::make('score')
                    ->numeric(decimalPlaces: 2)
                    ->extraHeaderAttributes(['class' => 'w-24'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap'])
                    ->placeholder('-'),
                TextColumn::make('data.reason')
                    ->label('Reason')
                    ->extraHeaderAttributes(['class' => 'w-48'])
                    ->placeholder('-'),
                TextColumn::make('data.context')
                    ->label('Calculation Context')
                    ->extraHeaderAttributes(['class' => 'min-w-[28rem]'])
                    ->html()
                    ->formatStateUsing(fn (mixed $state): string => $this->asCollapsibleJson($state, 'Show context'))
                    ->wrap(),
            ]);
    }

    private function prettyJson(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return '-';
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function asCollapsibleJson(mixed $value, string $label): string
    {
        $json = $this->prettyJson($value);

        if ($json === '-') {
            return '-';
        }

        $escaped = e($json);
        $summary = e($label);

        return '<details class="text-xs"><summary class="cursor-pointer text-primary-600">'.$summary.'</summary><pre class="mt-2 whitespace-pre-wrap text-xs leading-5">'.$escaped.'</pre></details>';
    }
}
