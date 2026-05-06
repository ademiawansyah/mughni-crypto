<?php

namespace App\Filament\Resources\Coins;

use App\Filament\Resources\Coins\Pages\ManageCoins;
use App\Models\Coin;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CoinResource extends Resource
{
    protected static ?string $model = Coin::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'Coin';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('symbol')
                    ->required(),
                TextInput::make('name'),
                FileUpload::make('image')
                    ->image(),
                TextInput::make('coin_gecko_id'),
                DateTimePicker::make('coin_data_last_updated'),
                DateTimePicker::make('last_fetched_at'),
                TextInput::make('market_cap')
                    ->numeric(),
                TextInput::make('volume_24h')
                    ->numeric(),
                TextInput::make('current_price')
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('raw_data'),
                Toggle::make('is_valid')
                    ->required(),
                TextInput::make('total_volume')
                    ->numeric(),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('symbol'),
                TextEntry::make('name')
                    ->placeholder('-'),
                ImageEntry::make('image')
                    ->placeholder('-'),
                TextEntry::make('coin_gecko_id')
                    ->placeholder('-'),
                TextEntry::make('coin_data_last_updated')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_fetched_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('market_cap')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('volume_24h')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('current_price')
                    ->money()
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
                IconEntry::make('is_valid')
                    ->boolean(),
                TextEntry::make('total_volume')
                    ->numeric()
                    ->placeholder('-'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('Coin')
            ->columns([
                TextColumn::make('symbol')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                ImageColumn::make('image'),
                TextColumn::make('coin_gecko_id')
                    ->searchable(),
                IconColumn::make('is_valid')
                    ->label('Pass Layer 2')
                    ->boolean()
                    ->headerTooltip('Not stablecoins, wrapped removed.')
                    ->tooltip(fn($state, $record) => $record->preSharedInvalidReasons()),
                TextColumn::make('coin_data_last_updated')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_fetched_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('market_cap')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('volume_24h')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('current_price')
                    ->money()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total_volume')
                    ->numeric()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                // EditAction::make(),
                // DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageCoins::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->orderByDesc('is_valid');
    }
}
