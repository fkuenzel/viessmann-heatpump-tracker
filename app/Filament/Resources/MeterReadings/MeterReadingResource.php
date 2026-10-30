<?php

namespace App\Filament\Resources\MeterReadings;

use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\EditMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Models\MeterReading;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MeterReadingResource extends Resource
{
    protected static ?string $model = MeterReading::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $navigationLabel = 'Zählerstände';

    protected static \UnitEnum | string | null $navigationGroup = 'Daten erfassen';

    protected static ?string $modelLabel = 'Zählerstand';

    protected static ?string $pluralModelLabel = 'Zählerstände';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DateTimePicker::make('timestamp')
                ->label('Ablesezeitpunkt')
                ->required()
                ->seconds(false)
                ->default(now())
                ->native(false),
            TextInput::make('meter_number')
                ->label('Zählernummer')
                ->default(fn () => \App\Models\Setting::get('meter_number'))
                ->maxLength(255),
            TextInput::make('counter_ht')
                ->label('Zählerstand HT (1.8.1)')
                ->numeric()
                ->step(0.001)
                ->suffix('kWh')
                ->required(),
            TextInput::make('counter_nt')
                ->label('Zählerstand NT (1.8.2)')
                ->numeric()
                ->step(0.001)
                ->suffix('kWh')
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('timestamp')
                    ->label('Ablesezeitpunkt')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('meter_number')
                    ->label('Zählernummer')
                    ->toggleable()
                    ->searchable(),
                TextColumn::make('counter_ht')
                    ->label('HT (1.8.1)')
                    ->numeric(decimalPlaces: 0)
                    ->suffix(' kWh')
                    ->sortable(),
                TextColumn::make('counter_nt')
                    ->label('NT (1.8.2)')
                    ->numeric(decimalPlaces: 0)
                    ->suffix(' kWh')
                    ->sortable(),
            ])
            ->defaultSort('timestamp', 'desc')
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
            'index' => ListMeterReadings::route('/'),
            'create' => CreateMeterReading::route('/create'),
            'edit' => EditMeterReading::route('/{record}/edit'),
        ];
    }
}
