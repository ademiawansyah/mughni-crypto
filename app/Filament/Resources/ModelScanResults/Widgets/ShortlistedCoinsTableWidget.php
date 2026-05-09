<?php

namespace App\Filament\Resources\ModelScanResults\Widgets;

use App\Models\ModelScanResult;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ShortlistedCoinsTableWidget extends TableWidget
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
            ->heading('Shortlisted Coins')
            ->description('Generated from result.results payload')
            ->records(fn(int $page, int $recordsPerPage): LengthAwarePaginator => $this->shortlistedPaginator($page, $recordsPerPage))
            ->queryStringIdentifier('shortlistedCoins')
            ->defaultPaginationPageOption(10)
            ->paginationPageOptions([10])
            ->paginated([10])
            ->columns([
                TextColumn::make('rank')
                    ->extraHeaderAttributes(['class' => 'w-16'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap']),
                TextColumn::make('symbol')
                    ->extraHeaderAttributes(['class' => 'w-28'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap']),
                TextColumn::make('price')
                    ->numeric(decimalPlaces: 8)
                    ->extraHeaderAttributes(['class' => 'w-36'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap'])
                    ->placeholder('-'),
                TextColumn::make('score')
                    ->numeric(decimalPlaces: 2)
                    ->extraHeaderAttributes(['class' => 'w-24'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap'])
                    ->placeholder('-'),
                TextColumn::make('stop_loss')
                    ->label('Stop Loss')
                    ->numeric(decimalPlaces: 8)
                    ->extraHeaderAttributes(['class' => 'w-36'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap'])
                    ->placeholder('-'),
                TextColumn::make('strategy')
                    ->extraHeaderAttributes(['class' => 'w-28'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap'])
                    ->placeholder('-'),
                TextColumn::make('timeframe')
                    ->label('Timeframe')
                    ->extraHeaderAttributes(['class' => 'w-32'])
                    ->extraCellAttributes(['class' => 'whitespace-nowrap'])
                    ->placeholder('-'),
                TextColumn::make('components')
                    ->label('Calculation Components')
                    ->extraHeaderAttributes(['class' => 'min-w-[28rem]'])
                    ->html()
                    ->getStateUsing(function (mixed $record): string {
                        $components = data_get($record, 'components');

                        if (! is_array($components) || count($components) === 0) {
                            return '-';
                        }

                        $content = $this->contextAsKeyValue($components);

                        return $content !== '-' ? $this->renderCollapsible($content, 'Show components') : '-';
                    })
                    ->wrap(),
            ]);
    }

    private function shortlistedPaginator(int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $results = data_get($this->record->result, 'results', []);

        if (! is_array($results)) {
            $results = [];
        }

        $rows = collect($results)
            ->map(function (array $item, int $index): array {
                $signalRow = is_array(data_get($item, 'signal'))
                    ? data_get($item, 'signal')
                    : $item;

                $entryTimeframe = (string) data_get($signalRow, 'metadata.entry_timeframe', '-');
                $structureTimeframe = (string) data_get($signalRow, 'metadata.structure_timeframe', '-');

                return [
                    'key' => (string) (data_get($signalRow, 'rank') ?? data_get($item, 'rank') ?? ($index + 1)) . '-' . $index,
                    'rank' => data_get($signalRow, 'rank') ?? data_get($item, 'rank'),
                    'symbol' => data_get($signalRow, 'symbol', '-'),
                    'price' => data_get($signalRow, 'price'),
                    'score' => data_get($signalRow, 'total_score', data_get($item, 'score')),
                    'stop_loss' => data_get($signalRow, 'metadata.stop_loss', data_get($item, 'metadata.stop_loss')),
                    'strategy' => data_get($signalRow, 'metadata.strategy', data_get($item, 'metadata.strategy', '-')),
                    'timeframe' => trim($entryTimeframe . ' / ' . $structureTimeframe),
                    'components' => data_get($signalRow, 'components', data_get($item, 'components')),
                ];
            })
            ->values();

        return $this->paginateCollection($rows, $page, $recordsPerPage);
    }

    private function paginateCollection(Collection $items, int $page, int $recordsPerPage): LengthAwarePaginator
    {
        $slice = $items->forPage($page, $recordsPerPage)->values();

        return new LengthAwarePaginator(
            items: $slice,
            total: $items->count(),
            perPage: $recordsPerPage,
            currentPage: $page,
            options: ['path' => request()->url()],
        );
    }

    private function contextAsKeyValue(mixed $value): string
    {
        if (! is_array($value) || count($value) === 0) {
            return '-';
        }

        $lines = $this->flattenContextLines($value);

        if (count($lines) === 0) {
            return '-';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  array<int|string, mixed>  $value
     * @return list<string>
     */
    private function flattenContextLines(array $value, string $prefix = ''): array
    {
        $lines = [];

        foreach ($value as $key => $item) {
            $currentKey = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($item)) {
                if ($item === []) {
                    $lines[] = $currentKey . ' : []';

                    continue;
                }

                $lines = array_merge($lines, $this->flattenContextLines($item, $currentKey));

                continue;
            }

            $lines[] = $currentKey . ' : ' . $this->stringifyContextValue($item);
        }

        return $lines;
    }

    private function stringifyContextValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    private function renderCollapsible(string $content, string $label): string
    {
        $escaped = e($content);
        $summary = e($label);

        return '<details class="text-xs"><summary class="cursor-pointer text-primary-600">' . $summary . '</summary><pre class="mt-2 whitespace-pre-wrap text-xs leading-5">' . $escaped . '</pre></details>';
    }
}
