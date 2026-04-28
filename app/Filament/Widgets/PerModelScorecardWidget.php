<?php

namespace App\Filament\Widgets;

use App\Services\Trading\SignalQueryService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Str;

/**
 * PerModelScorecardWidget
 *
 * Comparative scorecard for counter-trend, pre-pump, and momentum models.
 */
class PerModelScorecardWidget extends StatsOverviewWidget
{
    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        /** @var SignalQueryService $service */
        $service = app(SignalQueryService::class);
        $rows = $service->perModelScorecard();

        if ($rows === []) {
            return [
                Stat::make('Per-Model Scorecard', 'N/A')
                    ->description('No model signals available yet')
                    ->color('gray'),
            ];
        }

        return collect($rows)
            ->map(function (array $row): Stat {
                $model = Str::title(str_replace('_', ' ', $row['model']));

                return Stat::make($model, (string) $row['avg_confidence'].'% avg confidence')
                    ->description($row['actionable'].' actionable / '.$row['total'].' total, AI used '.$row['ai_usage_rate'].'%')
                    ->color($row['avg_confidence'] >= 60 ? 'success' : 'warning');
            })
            ->all();
    }
}
