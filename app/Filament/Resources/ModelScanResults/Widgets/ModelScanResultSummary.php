<?php

namespace App\Filament\Resources\ModelScanResults\Widgets;

use App\Models\ModelScanResult;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ModelScanResultSummary extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $dateNow = now()->toDateString();
        $records = ModelScanResult::query()
            ->select(['execution_date', 'supporting_data']);

        $totalScans = $records->count();
        $todayScans = $records->where('execution_date', $dateNow)->count();
        // $totalShortlisted = (int) $records->where('execution_date', $dateNow)->sum(fn(ModelScanResult $record): int => (int) data_get($record->supporting_data, 'shortlisted', 0));
        // $totalFailed = (int) $records->where('execution_date', $dateNow)->sum(fn(ModelScanResult $record): int => (int) data_get($record->supporting_data, 'failed_count', 0));

        return [
            Stat::make('Total Scan Executions', (string) $totalScans),
            Stat::make('Today Executions Today', (string) $todayScans),
        ];
    }
}
