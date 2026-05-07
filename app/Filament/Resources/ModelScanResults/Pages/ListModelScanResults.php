<?php

namespace App\Filament\Resources\ModelScanResults\Pages;

use App\Filament\Resources\ModelScanResults\ModelScanResultResource;
use App\Filament\Resources\ModelScanResults\Widgets\ModelScanResultSummary;
use Filament\Resources\Pages\ManageRecords;

class ListModelScanResults extends ManageRecords
{
    protected static string $resource = ModelScanResultResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ModelScanResultSummary::class,
        ];
    }
}
