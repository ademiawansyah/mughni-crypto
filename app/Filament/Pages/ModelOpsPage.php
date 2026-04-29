<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\ConsensusCoinsWidget;
use App\Filament\Widgets\CounterTrendSignalsTableWidget;
use App\Filament\Widgets\HighestConvictionSignalsWidget;
use App\Filament\Widgets\ModelExecutionHistoryWidget;
use App\Filament\Widgets\MomentumSignalsTableWidget;
use App\Filament\Widgets\PerModelScorecardWidget;
use App\Filament\Widgets\PrePumpSignalsTableWidget;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * ModelOpsPage
 *
 * Workspace landing page for model operations visibility, including
 * market context, MCP gate health, decision authority chain, and
 * model signal execution widgets.
 */
class ModelOpsPage extends Page
{
    protected static ?string $slug = 'model-ops';

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?string $navigationLabel = 'Model Ops';

    protected static ?string $title = 'Model Ops Workspace';

    protected static \BackedEnum|string|null $navigationIcon = Heroicon::RectangleStack;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.model-ops-page';

    protected function getHeaderWidgets(): array
    {
        return [
            PerModelScorecardWidget::class,
            CounterTrendSignalsTableWidget::class,
            PrePumpSignalsTableWidget::class,
            MomentumSignalsTableWidget::class,
            ModelExecutionHistoryWidget::class,
            HighestConvictionSignalsWidget::class,
            ConsensusCoinsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
