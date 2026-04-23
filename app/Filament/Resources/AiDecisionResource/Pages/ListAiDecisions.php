<?php

namespace App\Filament\Resources\AiDecisionResource\Pages;

use App\Filament\Resources\AiDecisionResource;
use Filament\Resources\Pages\ListRecords;

/**
 * ListAiDecisions
 *
 * Paginated list of all AI trading decisions.
 */
class ListAiDecisions extends ListRecords
{
    protected static string $resource = AiDecisionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
