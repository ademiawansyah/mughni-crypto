<?php

namespace App\Filament\Widgets;

use App\Services\Trading\SignalQueryService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * NotificationEligibilityWidget
 *
 * Shows notification-eligible signal counts after acceptance and deduplication.
 */
class NotificationEligibilityWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        /** @var SignalQueryService $service */
        $service = app(SignalQueryService::class);
        $stats = $service->notificationEligibility();

        return [
            Stat::make('Eligible BUY/SELL (Unique)', (string) $stats['eligible_unique_signals'])
                ->description('Accepted BUY/SELL signals after duplicate suppression')
                ->color($stats['eligible_unique_signals'] > 0 ? 'success' : 'gray'),

            Stat::make('Duplicate Groups', (string) $stats['duplicate_groups'])
                ->description('Duplicate signal groups blocked from notification eligibility')
                ->color($stats['duplicate_groups'] === 0 ? 'success' : 'warning'),

            Stat::make('Accepted vs Rejected Rows', $stats['accepted_actionable_rows'].' / '.$stats['rejected_rows'])
                ->description('Actionable accepted rows compared with rejected authority outcomes')
                ->color($stats['accepted_actionable_rows'] >= $stats['rejected_rows'] ? 'success' : 'warning'),
        ];
    }
}
