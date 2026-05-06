<?php

namespace App\Filament\Resources\ModelScanResults;

use App\Filament\Resources\ModelScanResults\Pages\ListModelScanResults;
use App\Filament\Resources\ModelScanResults\Pages\ViewModelScanResult;
use App\Models\ModelScanResult;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ModelScanResultResource extends Resource
{
    protected static ?string $model = ModelScanResult::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Model Scan History';

    protected static string|\UnitEnum|null $navigationGroup = 'Trading';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'execution_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('execution_id')
            ->columns([
                TextColumn::make('model_name')
                    ->label('Model')
                    ->searchable(),
                TextColumn::make('execution_id')
                    ->searchable()
                    ->copyable()
                    ->limit(24)
                    ->tooltip(fn (ModelScanResult $record): string => $record->execution_id),
                TextColumn::make('evaluated')
                    ->state(fn (ModelScanResult $record): int => (int) data_get($record->supporting_data, 'evaluated', 0)),
                TextColumn::make('shortlisted')
                    ->state(fn (ModelScanResult $record): int => (int) data_get($record->supporting_data, 'shortlisted', 0)),
                TextColumn::make('failed')
                    ->state(fn (ModelScanResult $record): int => (int) data_get($record->supporting_data, 'failed_count', 0)),
                TextColumn::make('minimum_score')
                    ->label('Min Score')
                    ->state(fn (ModelScanResult $record): string => (string) data_get($record->supporting_data, 'minimum_score', '-')),
                TextColumn::make('source_used')
                    ->label('Source')
                    ->state(fn (ModelScanResult $record): string => (string) data_get($record->supporting_data, 'source_used', '-'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordUrl(fn (ModelScanResult $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModelScanResults::route('/'),
            'view' => ViewModelScanResult::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->orderByDesc('id');
    }
}
