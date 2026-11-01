<?php

namespace App\Filament\WeatherData;

use App\Models\WeatherLog;
use App\Services\ApiLogAggregationService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Tagesweise Tabelle zum Temperaturvergleich - siehe TemperatureChartWidget
 * fuer den zugehoerigen Chart und die Begruendung, warum diese Klasse
 * bewusst ausserhalb von app/Filament/Widgets/ liegt (erscheint dadurch
 * nicht automatisch auf dem Haupt-Dashboard).
 *
 * Query-Basis ist WeatherLog mit source=archive_daily (echte, konsolidierte
 * Tagesmittelwerte aus Open-Meteos Historical-Weather-API) - die Anlagen-
 * Aussentemperatur wird pro Zeile per
 * ApiLogAggregationService::averageOutdoorTemp() dazugerechnet.
 */
class TemperatureTableWidget extends TableWidget
{
    protected int | string | array $columnSpan = 'full';

    protected function getTableHeading(): ?string
    {
        return 'Temperaturvergleich pro Tag';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                WeatherLog::query()
                    ->where('source', 'archive_daily')
                    ->orderByDesc('timestamp')
            )
            ->columns([
                TextColumn::make('timestamp')
                    ->label('Datum')
                    ->date('d.m.Y'),
                TextColumn::make('temperature')
                    ->label('Außentemp. extern (Kleve)')
                    ->state(fn (WeatherLog $record) => number_format((float) $record->temperature, 1, ',', '.') . ' °C'),
                TextColumn::make('humidity')
                    ->label('Luftfeuchtigkeit extern')
                    ->state(fn (WeatherLog $record) => number_format((float) $record->humidity, 0, ',', '.') . ' %'),
                TextColumn::make('anlage_temp')
                    ->label('Außentemp. Anlage')
                    ->state(function (WeatherLog $record) {
                        $value = app(ApiLogAggregationService::class)->averageOutdoorTemp($record->timestamp);

                        return $value !== null ? number_format($value, 1, ',', '.') . ' °C' : '–';
                    }),
                TextColumn::make('abweichung')
                    ->label('Abweichung Anlage − extern')
                    ->state(function (WeatherLog $record) {
                        $anlage = app(ApiLogAggregationService::class)->averageOutdoorTemp($record->timestamp);

                        if ($anlage === null) {
                            return '–';
                        }

                        $diff = $anlage - (float) $record->temperature;

                        return ($diff >= 0 ? '+' : '') . number_format($diff, 1, ',', '.') . ' °C';
                    }),
            ])
            ->defaultPaginationPageOption(14);
    }
}
