<?php

namespace App\Filament\Resources\GeneralConfigs\Pages;

use App\Filament\Resources\GeneralConfigs\GeneralConfigResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGeneralConfigs extends ManageRecords
{
    protected static string $resource = GeneralConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
