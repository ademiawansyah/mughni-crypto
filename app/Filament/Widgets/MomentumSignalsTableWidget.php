<?php

namespace App\Filament\Widgets;

class MomentumSignalsTableWidget extends ModelSignalsTableWidget
{
    protected static ?string $heading = 'Top 10 Momentum Signals';

    protected function modelKey(): string
    {
        return 'momentum';
    }
}
