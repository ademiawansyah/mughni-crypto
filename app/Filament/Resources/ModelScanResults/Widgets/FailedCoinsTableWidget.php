<?php

namespace App\Filament\Resources\ModelScanResults\Widgets;

use App\Models\ModelScanResult;
use App\Models\ModelScanResultDetail;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

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
            ->query(fn (): Builder => ModelScanResultDetail::query()
                ->where('model_scan_result_id', $this->record->id)
                ->where('is_passed', false)
                ->with('coin')
                ->orderBy('rank'))
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
                TextColumn::make('reason')
                    ->label('Reason')
                    ->extraHeaderAttributes(['class' => 'w-48'])
                    ->placeholder('-')
                    ->getStateUsing(function (ModelScanResultDetail $record): string {
                        return $this->reasonAsText($record->data);
                    }),
                TextColumn::make('context')
                    ->label('Calculation Context')
                    ->extraHeaderAttributes(['class' => 'min-w-[28rem]'])
                    ->html()
                    ->getStateUsing(function (ModelScanResultDetail $record): string {
                        $data = $record->data;

                        if ($data === null) {
                            return '-';
                        }

                        if (! is_array($data)) {
                            return '-';
                        }

                        if (count($data) === 0) {
                            return '-';
                        }

                        $content = $this->contextAsKeyValue($data);

                        return $content !== '-' ? $this->renderCollapsible($content) : '-';
                    })
                    ->wrap(),
            ]);
    }

    private function reasonAsText(mixed $data): string
    {
        if ($data === null) {
            return '-';
        }

        if (! is_array($data)) {
            return '-';
        }

        $reason = $data['reason'] ?? null;

        if (is_string($reason) && $reason !== '') {
            return $reason;
        }

        $rejectionReason = $data['rejection_reason'] ?? null;

        if (is_string($rejectionReason) && $rejectionReason !== '') {
            return $rejectionReason;
        }

        return '-';
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
            $currentKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($item)) {
                if ($item === []) {
                    $lines[] = $currentKey.' : []';

                    continue;
                }

                $lines = array_merge($lines, $this->flattenContextLines($item, $currentKey));

                continue;
            }

            $lines[] = $currentKey.' : '.$this->stringifyContextValue($item);
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

    private function renderCollapsible(string $content): string
    {
        $escaped = e($content);

        return '<details class="text-xs"><summary class="cursor-pointer text-primary-600">Show context</summary><pre class="mt-2 whitespace-pre-wrap text-xs leading-5">'.$escaped.'</pre></details>';
    }
}
