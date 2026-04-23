<?php

namespace App\Filament\Widgets;

use App\Services\Trading\SignalQueryService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * PerformanceStatsWidget
 *
 * Displays aggregate win rate, average profit, and average drawdown
 * across all resolved AI decisions.
 */
class PerformanceStatsWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        /** @var SignalQueryService $service */
        $service = app(SignalQueryService::class);
        $stats = $service->performanceStats();

        return [
            Stat::make('Win Rate', $stats['win_rate'].'%')
                ->description($stats['wins'].' wins out of '.$stats['total'].' resolved')
                ->color($stats['win_rate'] >= 50 ? 'success' : 'danger'),

            Stat::make('Avg Max Profit', number_format($stats['avg_profit'], 4))
                ->description('Average max profit across resolved decisions')
                ->color('info'),

            Stat::make('Avg Max Drawdown', number_format($stats['avg_drawdown'], 4))
                ->description('Average max drawdown across resolved decisions')
                ->color('warning'),
        ];
    }
}
