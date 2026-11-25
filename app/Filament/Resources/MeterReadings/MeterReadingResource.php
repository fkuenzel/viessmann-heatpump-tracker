<?php

namespace App\Filament\Resources\MeterReadings;

use App\Enums\MeterReadingReason;
use App\Filament\Resources\MeterReadings\Pages\CreateMeterReading;
use App\Filament\Resources\MeterReadings\Pages\EditMeterReading;
use App\Filament\Resources\MeterReadings\Pages\ListMeterReadings;
use App\Models\MeterReading;
use App\Models\Setting;
use App\Services\MeterReadingPlausibilityService;
use BackedEnum;
use Closure;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

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
            Select::make('reason')
                ->label('Grund der Ablesung')
                ->options(MeterReadingReason::options())
                ->default(MeterReadingReason::Regular->value)
                ->required()
                ->native(false)
                ->live()
                ->helperText('Zählertausch und Startwert beginnen eine neue Messreihe: Zum Vorgänger wird kein Intervall berechnet. Ein Schätzwert macht angrenzende Intervalle unzuverlässig.'),
            TextInput::make('meter_number')
                ->label('Zählernummer')
                ->default(fn () => Setting::get('meter_number'))
                ->maxLength(255),
            TextInput::make('counter_ht')
                ->label('Zählerstand HT (1.8.1)')
                ->numeric()
                ->step(0.001)
                ->suffix('kWh')
                ->required()
                ->rules([self::plausibilityRule()]),
            TextInput::make('counter_nt')
                ->label('Zählerstand NT (1.8.2)')
                ->numeric()
                ->step(0.001)
                ->suffix('kWh')
                ->required()
                ->rules([self::plausibilityRule()]),
            Textarea::make('note')
                ->label('Notiz')
                ->rows(2)
                ->maxLength(1000)
                ->required(fn (Get $get) => $get('reason') === MeterReadingReason::Other->value)
                ->columnSpanFull(),
        ]);
    }

    /**
     * Harte Plausibilitaetspruefung (Zaehlerstand darf nicht sinken bzw. den
     * Nachfolger ueberschreiten). Fehlermeldungen werden dem jeweiligen Feld
     * (HT/NT) zugeordnet.
     */
    private static function plausibilityRule(): Closure
    {
        return fn (Get $get, ?\Illuminate\Database\Eloquent\Model $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get, $record) {
            if (blank($get('timestamp')) || blank($get('counter_ht')) || blank($get('counter_nt'))) {
                return;
            }

            $errors = app(MeterReadingPlausibilityService::class)->errors(
                Carbon::parse($get('timestamp')),
                (float) $get('counter_ht'),
                (float) $get('counter_nt'),
                MeterReadingReason::tryFrom((string) $get('reason')) ?? MeterReadingReason::Regular,
                $record?->getKey(),
            );

            $prefix = str_ends_with($attribute, 'counter_ht') ? 'HT' : 'NT';

            foreach ($errors as $error) {
                if (str_starts_with($error, $prefix)) {
                    $fail($error);

                    return;
                }
            }
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('timestamp')
                    ->label('Ablesezeitpunkt')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
                TextColumn::make('reason')
                    ->label('Grund')
                    ->badge()
                    ->formatStateUsing(fn (MeterReadingReason $state) => $state->label())
                    ->color(fn (MeterReadingReason $state) => $state->color())
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
                TextColumn::make('note')
                    ->label('Notiz')
                    ->limit(40)
                    ->tooltip(fn (MeterReading $record) => $record->note)
                    ->toggleable(),
                TextColumn::make('source')
                    ->label('Quelle')
                    ->formatStateUsing(fn (?string $state) => MeterReading::SOURCES[$state] ?? $state)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('timestamp', 'desc')
            ->filters([
                SelectFilter::make('reason')
                    ->label('Grund')
                    ->options(MeterReadingReason::options())
                    ->multiple(),
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
