<?php

namespace App\Filament\Resources\Coins\Pages;

use App\Filament\Resources\Coins\CoinResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCoins extends ManageRecords
{
    protected static string $resource = CoinResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // CreateAction::make(),
        ];
    }
}
