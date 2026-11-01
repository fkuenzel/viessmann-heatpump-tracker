<?php

namespace App\Filament\WeatherData;

use App\Models\WeatherLog;
use App\Services\ApiLogAggregationService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Aktuelle Aussentemperatur: Anlagen-Sensor + externe Open-Meteo-Messung
 * (Kleve), nebeneinander (Fabians Wunsch, 30.10.2026). Vom Dashboard auf die
 * Seite "Wetterdaten" verschoben (vorher App\Filament\Widgets\WeatherWidget,
 * mittlerweile geloescht) - die beiden Werte stehen zwar bereits in der
 * globalen Topbar (AdminPanelProvider::panel(), renderHook
 * GLOBAL_SEARCH_BEFORE), Fabian wollte sie trotzdem zusaetzlich auf dieser
 * Seite sehen, aber sichtbar getrennt von der Prognose (siehe
 * ForecastWeatherWidget) und vom historischen Vergleich
 * (TemperatureChartWidget/TemperatureTableWidget) - drei eigene Abschnitte
 * statt einer gemeinsamen Kachelreihe wie urspruenglich im alten
 * WeatherWidget.
 *
 * source='live_snapshot' explizit gefiltert statt nur nach juengstem
 * timestamp zu sortieren - weather_log enthaelt seit der Archiv-Anbindung
 * (Fahrplan Abschnitt 4.31) auch archive_daily-Zeilen (Tagesmittelwerte).
 * Die sind in der Praxis immer aelter als der aktuelle Snapshot, aber ohne
 * den expliziten Filter waere das nur zufaellig statt garantiert korrekt.
 */
class CurrentWeatherWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Aktuell';

    protected int | array | null $columns = 2;

    protected function getStats(): array
    {
        $aggregation = app(ApiLogAggregationService::class);
        $outdoorTemp = $aggregation->lastSnapshotValue(now(), 'outdoor_temp');
        $outdoorTempStand = $aggregation->lastSnapshotTimestamp(now(), 'outdoor_temp');

        $latestWeather = WeatherLog::query()
            ->where('source', 'live_snapshot')
            ->latest('timestamp')
            ->first();

        return [
            Stat::make(
                'Außentemp. (Anlage)',
                $outdoorTemp !== null ? number_format($outdoorTemp, 1, ',', '.') . ' °C' : '–',
            )->description($outdoorTempStand ? 'Stand ' . $outdoorTempStand->format('d.m. H:i') : null),
            Stat::make(
                'Außentemp. (Kleve)',
                $latestWeather ? number_format((float) $latestWeather->temperature, 1, ',', '.') . ' °C' : '–',
            )->description(
                $latestWeather
                    ? 'Luftfeuchtigkeit ' . number_format((float) $latestWeather->humidity, 0, ',', '.') . ' % · Stand ' . $latestWeather->timestamp->format('d.m. H:i')
                    : null,
            ),
        ];
    }
}
