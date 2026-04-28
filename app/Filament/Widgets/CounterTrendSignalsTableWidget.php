<?php

namespace App\Filament\Widgets;

class CounterTrendSignalsTableWidget extends ModelSignalsTableWidget
{
    protected static ?string $heading = 'Counter-Trend Signals';

    protected function modelKey(): string
    {
        return 'counter_trend';
    }
}
