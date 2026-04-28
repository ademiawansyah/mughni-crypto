<?php

namespace App\Filament\Widgets;

class MomentumSignalsTableWidget extends ModelSignalsTableWidget
{
    protected static ?string $heading = 'Momentum Signals';

    protected function modelKey(): string
    {
        return 'momentum';
    }
}
