<?php

namespace App\Filament\Widgets;

class PrePumpSignalsTableWidget extends ModelSignalsTableWidget
{
    protected static ?string $heading = 'Pre-Pump Signals';

    protected function modelKey(): string
    {
        return 'pre_pump';
    }
}
