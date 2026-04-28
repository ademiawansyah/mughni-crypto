<?php

namespace App\Filament\Widgets;

use App\Services\Trading\SignalQueryService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * McpGateHealthWidget
 *
 * Shows MCP pre-filter health metrics for recent summary context rows.
 */
class McpGateHealthWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        /** @var SignalQueryService $service */
        $service = app(SignalQueryService::class);
        $stats = $service->mcpGateHealth();

        if (($stats['passed'] + $stats['failed']) === 0) {
            return [
                Stat::make('MCP Pass Rate', 'N/A')
                    ->description('No context rows available yet')
                    ->color('gray'),
                Stat::make('Average MCP Score', 'N/A')
                    ->description('No scored MCP evaluations yet')
                    ->color('gray'),
                Stat::make('Dominant Candidate', 'N/A')
                    ->description('No candidate distribution available')
                    ->color('gray'),
            ];
        }

        return [
            Stat::make('MCP Pass Rate', number_format($stats['pass_rate'], 1).'%')
                ->description($stats['passed'].' pass / '.$stats['failed'].' fail in last 60m')
                ->color($stats['pass_rate'] >= 60 ? 'success' : 'warning'),

            Stat::make('Average MCP Score', $stats['average_score'] !== null ? number_format($stats['average_score'], 1) : 'N/A')
                ->description('Score average across recent MCP-evaluated rows')
                ->color($stats['average_score'] !== null && $stats['average_score'] >= 60 ? 'success' : 'warning'),

            Stat::make('Dominant Candidate', $stats['dominant_candidate'])
                ->description('Most frequent MCP candidate in last 60m')
                ->color(match ($stats['dominant_candidate']) {
                    'BUY' => 'success',
                    'SELL' => 'danger',
                    'HOLD' => 'warning',
                    default => 'gray',
                }),
        ];
    }
}
