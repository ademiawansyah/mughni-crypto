<?php

namespace App\Filament\Resources\ModelScanResults\Pages;

use App\Filament\Resources\ModelScanResults\ModelScanResultResource;
use App\Models\ModelScanResult;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewModelScanResult extends ViewRecord
{
    protected static string $resource = ModelScanResultResource::class;

    protected string $view = 'filament.resources.model-scan-results.pages.view-model-scan-result';

    /**
     * Defines the Execution Summary infolist schema for the detail page.
     */
    public function infolist(Schema $schema): Schema
    {
        /** @var ModelScanResult $record */
        $record = $this->getRecord();

        return $schema->components([
            Section::make('Execution Summary')
                ->columns(3)
                ->schema([
                    TextEntry::make('model_name')
                        ->label('Model'),
                    TextEntry::make('execution_id')
                        ->label('Execution ID')
                        ->columnSpan(2)
                        ->copyable(),
                    TextEntry::make('execution_date')
                        ->label('Execution Date')
                        ->date('M j, Y'),
                    TextEntry::make('supporting_data.evaluated')
                        ->label('Evaluated')
                        ->default('-'),
                    TextEntry::make('supporting_data.shortlisted')
                        ->label('Shortlisted')
                        ->default('-'),
                    TextEntry::make('supporting_data.failed_count')
                        ->label('Failed')
                        ->default('-'),
                    TextEntry::make('supporting_data.minimum_score')
                        ->label('Minimum Score')
                        ->default('-'),
                    TextEntry::make('supporting_data.source_used')
                        ->label('Source')
                        ->default('-'),
                    TextEntry::make('created_at')
                        ->label('Created At')
                        ->dateTime('M j, Y H:i:s'),
                ]),
        ]);
    }
}
