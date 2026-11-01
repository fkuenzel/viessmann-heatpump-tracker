<?php

namespace App\Filament\Resources\ApiLogs;

use App\Filament\Resources\ApiLogs\Pages\CreateApiLog;
use App\Filament\Resources\ApiLogs\Pages\EditApiLog;
use App\Filament\Resources\ApiLogs\Pages\ListApiLogs;
use App\Models\ApiLog;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Alle Rohwerte aus api_log (automatische Syncs, manuelle Syncs und
 * manuelle Nachtraege) in einer Oberflaeche einsehbar und bearbeitbar -
 * auf Fabians Wunsch (28.10.2026), da er in der Viessmann-App alle Werte
 * sieht und auch alle korrigieren/nachtragen koennen moechte, nicht nur
 * die per Snapshot nicht nachholbaren Thermik-/Sensor-Werte (siehe
 * Fahrplan Abschnitt 4.10/4.12).
 *
 * Neue Zeilen werden standardmaessig als source=backfill, day_index=0
 * angelegt (die typische "Tageswert aus der App nachtragen"-Nutzung),
 * beides laesst sich aber ueberschreiben. Beim Bearbeiten einer
 * bestehenden Zeile werden source/day_index/timestamp nicht mehr
 * automatisch ueberschrieben - Fabian aendert direkt, was er aendern will.
 *
 * Seit Fabians Wunsch (30.10.2026) zusaetzlich die "Geraetestatus"-Felder
 * (Vorlauf-/Ruecklauftemperatur, Anlagendruck, interne Pumpe, Volumenstrom,
 * Sekundaerkreis-Vorlauftemperatur, Verdichterstatus/-Phase) - gleiche
 * Snapshot-Logik wie Thermik/Aussentemperatur/Kompressor-Statistik, per
 * Sync nur fuer den laufenden Tag befuellt, hier aber wie alle anderen
 * Felder fuer jede Zeile nachtragbar/korrigierbar.
 */
class ApiLogResource extends Resource
{
    protected static ?string $model = ApiLog::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedDocumentPlus;

    protected static ?string $navigationLabel = 'Anlagenwerte';

    protected static \UnitEnum | string | null $navigationGroup = 'Daten erfassen';

    protected static ?string $modelLabel = 'Anlagenwert';

    protected static ?string $pluralModelLabel = 'Anlagenwerte';

    public const SOURCES = [
        'scheduled' => 'Automatischer Sync',
        'manual' => 'Manueller Sync',
        'backfill' => 'Nachtrag',
    ];

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            DateTimePicker::make('timestamp')
                ->label('Zeitpunkt')
                ->required()
                ->seconds(false)
                ->default(now()->setTime(12, 0))
                ->native(false),
            Select::make('source')
                ->label('Quelle')
                ->options(self::SOURCES)
                ->default('backfill')
                ->native(false)
                ->required(),
            TextInput::make('day_index')
                ->label('Tag-Index')
                ->helperText('0 = Tageswert für den Zeitpunkt selbst, 1 = ein Tag davor, usw.')
                ->numeric()
                ->default(0)
                ->required(),
            DateTimePicker::make('measured_at')
                ->label('Messuhrzeit')
                ->helperText('00:00 Uhr = ganztägige Messung (wird als "24 Std. Messung" angezeigt). Konkrete Uhrzeit eintragen, wenn du den Wert zu einem bestimmten Zeitpunkt in der App abgelesen hast.')
                ->seconds(false)
                ->default(now()->startOfDay())
                ->native(false),
            TextInput::make('power_heating_kwh')
                ->label('Stromverbrauch Heizen')
                ->numeric()
                ->step(0.01)
                ->suffix('kWh'),
            TextInput::make('power_dhw_kwh')
                ->label('Stromverbrauch Warmwasser')
                ->numeric()
                ->step(0.01)
                ->suffix('kWh'),
            TextInput::make('power_total_kwh')
                ->label('Stromverbrauch Gesamt')
                ->numeric()
                ->step(0.01)
                ->suffix('kWh'),
            TextInput::make('thermal_heating_kwh')
                ->label('Thermische Energie Heizen')
                ->numeric()
                ->step(0.01)
                ->suffix('kWh'),
            TextInput::make('thermal_dhw_kwh')
                ->label('Thermische Energie Warmwasser')
                ->numeric()
                ->step(0.01)
                ->suffix('kWh'),
            TextInput::make('outdoor_temp')
                ->label('Außentemperatur')
                ->numeric()
                ->step(0.1)
                ->suffix('°C'),
            TextInput::make('compressor_starts')
                ->label('Kompressor-Starts (kumuliert)')
                ->numeric(),
            TextInput::make('compressor_hours')
                ->label('Kompressor-Laufzeit (kumuliert)')
                ->numeric()
                ->step(0.1)
                ->suffix('h'),
            TextInput::make('flow_temp')
                ->label('Vorlauftemperatur')
                ->numeric()
                ->step(0.1)
                ->suffix('°C'),
            TextInput::make('return_temp')
                ->label('Rücklauftemperatur')
                ->numeric()
                ->step(0.1)
                ->suffix('°C'),
            TextInput::make('system_pressure')
                ->label('Anlagendruck')
                ->numeric()
                ->step(0.1)
                ->suffix('bar'),
            Select::make('internal_pump_status')
                ->label('Interne Pumpe')
                ->options(['on' => 'EIN', 'off' => 'AUS'])
                ->native(false),
            TextInput::make('volumetric_flow')
                ->label('Volumenstrom')
                ->numeric()
                ->step(1)
                ->suffix('l/h'),
            TextInput::make('secondary_flow_temp')
                ->label('Vorlauftemperatur Sekundärkreis')
                ->numeric()
                ->step(0.1)
                ->suffix('°C'),
            Select::make('compressor_active')
                ->label('Verdichterstatus')
                ->options([1 => 'EIN', 0 => 'AUS'])
                ->native(false),
            TextInput::make('compressor_phase')
                ->label('Verdichter-Phase')
                ->helperText('Roh-Status aus der API, z. B. "ready" oder "running".'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_date')
                    ->label('Messdatum')
                    ->state(fn (ApiLog $record) => $record->reference_date->format('d.m.Y'))
                    ->tooltip('Kalendertag, auf den sich dieser Wert bezieht (Zeitpunkt minus Tag-Index).')
                    ->weight('bold')
                    ->sortable(query: function ($query, string $direction) {
                        $query->orderByRaw("date(timestamp, '-' || day_index || ' days') {$direction}");
                    }),
                TextColumn::make('measured_at')
                    ->label('Messuhrzeit')
                    ->state(fn (ApiLog $record) => $record->is_full_day_measurement
                        ? '24 Std. Messung'
                        : $record->measured_at->format('H:i'))
                    ->badge(fn (ApiLog $record) => $record->is_full_day_measurement)
                    ->color(fn (ApiLog $record) => $record->is_full_day_measurement ? 'gray' : 'success')
                    ->tooltip(fn (ApiLog $record) => $record->is_full_day_measurement
                        ? 'Ganztägige Messung ohne bekannte Uhrzeit - 24 Std. gesamt.'
                        : 'Zeitpunkt, zu dem dieser Wert tatsächlich gemessen/abgelesen wurde.')
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Quelle')
                    ->formatStateUsing(fn (?string $state) => self::SOURCES[$state] ?? $state)
                    ->badge()
                    ->sortable(),
                TextColumn::make('day_index')
                    ->label('Tag-Index')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('timestamp')
                    ->label('Sync-Zeitpunkt')
                    ->dateTime('d.m.Y H:i')
                    ->tooltip('Zeitpunkt des API-Pulls bzw. bei Nachtraegen der frei gewaehlte Zeitpunkt - nicht der Tag, auf den sich der Wert bezieht (siehe Messdatum).')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('power_total_kwh')
                    ->label('Strom Gesamt')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' kWh')
                    ->toggleable(),
                TextColumn::make('thermal_heating_kwh')
                    ->label('Therm. Heizen')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' kWh')
                    ->toggleable(),
                TextColumn::make('thermal_dhw_kwh')
                    ->label('Therm. WW')
                    ->numeric(decimalPlaces: 2)
                    ->suffix(' kWh')
                    ->toggleable(),
                TextColumn::make('outdoor_temp')
                    ->label('Außentemp.')
                    ->numeric(decimalPlaces: 1)
                    ->suffix(' °C')
                    ->toggleable(),
                TextColumn::make('compressor_starts')
                    ->label('Kompr.-Starts')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('compressor_hours')
                    ->label('Kompr.-Stunden')
                    ->numeric(decimalPlaces: 1)
                    ->suffix(' h')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('flow_temp')
                    ->label('Vorlauf')
                    ->numeric(decimalPlaces: 1)
                    ->suffix(' °C')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('return_temp')
                    ->label('Rücklauf')
                    ->numeric(decimalPlaces: 1)
                    ->suffix(' °C')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('system_pressure')
                    ->label('Anlagendruck')
                    ->numeric(decimalPlaces: 1)
                    ->suffix(' bar')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('internal_pump_status')
                    ->label('Int. Pumpe')
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        'on' => 'EIN',
                        'off' => 'AUS',
                        default => $state,
                    })
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('volumetric_flow')
                    ->label('Volumenstrom')
                    ->numeric(decimalPlaces: 0)
                    ->suffix(' l/h')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('secondary_flow_temp')
                    ->label('Vorlauf Sek.')
                    ->numeric(decimalPlaces: 1)
                    ->suffix(' °C')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('compressor_active')
                    ->label('Verdichter')
                    ->formatStateUsing(fn (?bool $state) => $state === null ? null : ($state ? 'EIN' : 'AUS'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('reference_date', 'desc')
            ->filters([
                SelectFilter::make('source')
                    ->label('Quelle')
                    ->options(self::SOURCES),
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
            'index' => ListApiLogs::route('/'),
            'create' => CreateApiLog::route('/create'),
            'edit' => EditApiLog::route('/{record}/edit'),
        ];
    }
}
