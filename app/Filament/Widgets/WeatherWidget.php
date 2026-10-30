<?php

namespace App\Filament\Widgets;

use App\Models\Setting;
use App\Models\WeatherForecastLog;
use App\Models\WeatherLog;
use App\Services\ApiLogAggregationService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Aktuelle Temperatur/Luftfeuchtigkeit (Anlagen-Sensor + externe Open-Meteo-
 * Daten fuer Kleve) und die Kurzfrist-Prognose ueber den in den Settings
 * konfigurierten Horizont (forecast_horizon_days, Fahrplan Abschnitt 5).
 * Zahlenformat seit 29.10.2026 deutsch (Komma statt Punkt, Abschnitt 4.27) -
 * war als einziges Widget noch auf PHP-Standardformat, inkonsistent zu den
 * uebrigen Dashboard-Widgets.
 *
 * Jede Kachel zeigt seit 29.10.2026 zusaetzlich als helle Zusatzzeile, wie
 * aktuell ihr Wert ist (Fabians Wunsch, Abschnitt 4.30): "Stand HH:mm" bei
 * den Anlagen-/Kleve-Werten (Sync- bzw. Open-Meteo-Abrufzeitpunkt), bei der
 * Prognose zusaetzlich "Abgerufen HH:mm" (fetched_at), da sich die Prognose
 * selbst erst in der Zukunft bewahrheitet.
 */
class WeatherWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 60;

    protected function getStats(): array
    {
        $aggregation = app(ApiLogAggregationService::class);
        $outdoorTemp = $aggregation->lastSnapshotValue(now(), 'outdoor_temp');
        $outdoorTempStand = $aggregation->lastSnapshotTimestamp(now(), 'outdoor_temp');
        $latestWeather = WeatherLog::query()->latest('timestamp')->first();

        $stats = [
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

        $horizonDays = (int) Setting::get('forecast_horizon_days', 3);

        $forecast = WeatherForecastLog::query()
            ->where('forecast_for', '>=', now()->startOfDay())
            ->orderByDesc('fetched_at')
            ->get()
            ->unique('forecast_for')
            ->sortBy('forecast_for')
            ->take($horizonDays);

        foreach ($forecast as $day) {
            /** @var WeatherForecastLog $day */
            $stats[] = Stat::make(
                Carbon::parse($day->forecast_for)->isToday()
                    ? 'Heute'
                    : Carbon::parse($day->forecast_for)->translatedFormat('D, d.m.'),
                number_format((float) $day->forecast_temperature, 1, ',', '.') . ' °C',
            )->description(
                'Luftfeuchtigkeit ' . number_format((float) $day->forecast_humidity, 0, ',', '.') . ' % · Abgerufen ' . Carbon::parse($day->fetched_at)->format('d.m. H:i'),
            );
        }

        return $stats;
    }
}
