<?php

namespace App\Filament\Resources\PriceHistories;

use App\Filament\Resources\PriceHistories\Pages\CreatePriceHistory;
use App\Filament\Resources\PriceHistories\Pages\EditPriceHistory;
use App\Filament\Resources\PriceHistories\Pages\ListPriceHistories;
use App\Models\PriceHistory;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PriceHistoryResource extends Resource
{
    protected static ?string $model = PriceHistory::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Stromtarife';

    protected static \UnitEnum | string | null $navigationGroup = 'Konfiguration';

    protected static ?string $modelLabel = 'Stromtarif';

    protected static ?string $pluralModelLabel = 'Stromtarife';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('valid_from')
                ->label('Gültig ab')
                ->required()
                ->default(now())
                ->native(false),
            TextInput::make('provider_name')
                ->label('Anbieter')
                ->required()
                ->maxLength(255),
            TextInput::make('tariff_name')
                ->label('Tarifname')
                ->required()
                ->maxLength(255),
            TextInput::make('base_fee')
                ->label('Grundgebühr')
                ->numeric()
                ->step(0.01)
                ->suffix('€')
                ->required(),
            Select::make('base_fee_period')
                ->label('Abrechnungszeitraum')
                ->options([
                    'monthly' => 'monatlich',
                    'yearly' => 'jährlich',
                ])
                ->required()
                ->native(false),
            TextInput::make('ht_price')
                ->label('HT-Preis')
                ->numeric()
                ->step(0.0001)
                ->suffix('€/kWh')
                ->required(),
            TextInput::make('nt_price')
                ->label('NT-Preis')
                ->numeric()
                ->step(0.0001)
                ->suffix('€/kWh')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('valid_from')
                    ->label('Gültig ab')
                    ->date('d.m.Y')
                    ->sortable(),
                TextColumn::make('provider_name')
                    ->label('Anbieter')
                    ->searchable(),
                TextColumn::make('tariff_name')
                    ->label('Tarif')
                    ->searchable(),
                TextColumn::make('base_fee')
                    ->label('Grundgebühr')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' €')
                    ->formatStateUsing(fn ($record, $state) => $state . ($record->base_fee_period === 'yearly' ? ' / Jahr' : ' / Monat')),
                TextColumn::make('ht_price')
                    ->label('HT-Preis')
                    ->numeric(decimalPlaces: 4)
                    ->suffix(' €/kWh'),
                TextColumn::make('nt_price')
                    ->label('NT-Preis')
                    ->numeric(decimalPlaces: 4)
                    ->suffix(' €/kWh'),
            ])
            ->defaultSort('valid_from', 'desc')
            ->filters([
                //
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceHistories::route('/'),
            'create' => CreatePriceHistory::route('/create'),
            'edit' => EditPriceHistory::route('/{record}/edit'),
        ];
    }
}
