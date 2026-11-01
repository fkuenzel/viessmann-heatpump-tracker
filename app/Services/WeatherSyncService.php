<?php

namespace App\Services;

use App\Exceptions\ExternalApiException;
use App\Models\Setting;
use App\Models\WeatherForecastLog;
use App\Models\WeatherLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Holt aktuelle Wetterdaten und eine Kurzfrist-Prognose von Open-Meteo
 * (kostenlos, kein API-Key) fuer den konfigurierten Standort.
 *
 * Der Standort kommt aus den settings (weather_latitude/weather_longitude,
 * ueber die SettingsPage per Ortsname + Geocoding gepflegt, siehe
 * GeocodingService) statt wie urspruenglich fest auf Kleve im Code
 * verdrahtet zu sein - notwendig fuer die geplante Mehrbenutzer-
 * Veroeffentlichung auf GitHub (Github-Fahrplan Abschnitt 3): jeder
 * Betreiber hat einen anderen Standort fuer seine Anlage. Ist kein
 * Standort hinterlegt (frische Installation vor der Einrichtung,
 * oder Standort bewusst geloescht), ueberspringen sync()/backfillArchive()
 * den Lauf vollstaendig und ohne Fehler (Abschnitt 4).
 */
class WeatherSyncService
{
    public function isLocationConfigured(): bool
    {
        return Setting::get('weather_latitude') !== null
            && Setting::get('weather_longitude') !== null;
    }

    public function sync(): void
    {
        if (! $this->isLocationConfigured()) {
            return;
        }

        $horizonDays = (int) Setting::get('forecast_horizon_days', 3);
        $horizonDays = max(1, min($horizonDays, 16));

        $response = Http::get('https://api.open-meteo.com/v1/forecast', [
            'latitude' => (float) Setting::get('weather_latitude'),
            'longitude' => (float) Setting::get('weather_longitude'),
            'current' => 'temperature_2m,relative_humidity_2m',
            'daily' => 'temperature_2m_mean,relative_humidity_2m_mean',
            'forecast_days' => $horizonDays,
            'timezone' => 'Europe/Berlin',
        ]);

        if ($response->failed()) {
            throw new ExternalApiException('Open-Meteo-Abruf fehlgeschlagen: ' . $response->body());
        }

        $data = $response->json();
        $now = Carbon::now();

        $current = $data['current'] ?? [];

        if (isset($current['temperature_2m'], $current['relative_humidity_2m'])) {
            WeatherLog::create([
                'timestamp' => $now,
                'temperature' => $current['temperature_2m'],
                'humidity' => $current['relative_humidity_2m'],
                'source' => 'live_snapshot',
            ]);
        }

        $daily = $data['daily'] ?? [];
        $dates = $daily['time'] ?? [];
        $temperatures = $daily['temperature_2m_mean'] ?? [];
        $humidities = $daily['relative_humidity_2m_mean'] ?? [];

        foreach ($dates as $index => $date) {
            if (! isset($temperatures[$index], $humidities[$index])) {
                continue;
            }

            WeatherForecastLog::create([
                'fetched_at' => $now,
                'forecast_for' => Carbon::parse($date)->startOfDay(),
                'forecast_temperature' => $temperatures[$index],
                'forecast_humidity' => $humidities[$index],
            ]);
        }
    }

    /**
     * Holt echte, konsolidierte Tagesmittelwerte (Temperatur + Luftfeuchtigkeit)
     * aus Open-Meteos Historical-Weather-API fuer einen Datumsbereich.
     *
     * Im Gegensatz zu den stuendlichen live_snapshot-Zeilen aus sync() ist das
     * unabhaengig davon, ob der lokale Scheduler zum jeweiligen Zeitpunkt
     * tatsaechlich lief (Fabians Entscheidung, 30.10.2026, Seite
     * "Wetterdaten"): fuer eine fundierte thermische Analyse ueber
     * Monate sollen die externen Wetterdaten vollstaendig vorliegen, auch
     * wenn der Rechner mal tagelang aus war.
     *
     * Idempotent per updateOrCreate auf [timestamp, source] - ein wiederholter
     * Aufruf (z. B. der naechtliche Scheduler-Job fuer den jeweiligen Vortag)
     * erzeugt keine Dubletten, aktualisiert aber einen Wert, falls Open-Meteo
     * ihn nachtraeglich korrigiert.
     *
     * @return int Anzahl geschriebener/aktualisierter Tage (0, falls kein
     *              Standort konfiguriert ist)
     */
    public function backfillArchive(Carbon $from, Carbon $to): int
    {
        if (! $this->isLocationConfigured()) {
            return 0;
        }

        $response = Http::get('https://archive-api.open-meteo.com/v1/archive', [
            'latitude' => (float) Setting::get('weather_latitude'),
            'longitude' => (float) Setting::get('weather_longitude'),
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
            'daily' => 'temperature_2m_mean,relative_humidity_2m_mean',
            // Fallback-Quelle fuer die Luftfeuchtigkeit, falls die Archiv-API
            // relative_humidity_2m_mean als Tageswert (noch) nicht liefert -
            // siehe groupHourlyHumidityByDate().
            'hourly' => 'relative_humidity_2m',
            'timezone' => 'Europe/Berlin',
        ]);

        if ($response->failed()) {
            throw new ExternalApiException('Open-Meteo-Archiv-Abruf fehlgeschlagen: ' . $response->body());
        }

        $data = $response->json();

        $daily = $data['daily'] ?? [];
        $dates = $daily['time'] ?? [];
        $temperatures = $daily['temperature_2m_mean'] ?? [];
        $dailyHumidities = $daily['relative_humidity_2m_mean'] ?? [];

        $hourlyHumidityByDate = $this->groupHourlyHumidityByDate($data['hourly'] ?? []);

        $written = 0;

        foreach ($dates as $index => $date) {
            if (! isset($temperatures[$index]) || $temperatures[$index] === null) {
                continue;
            }

            $humidity = $dailyHumidities[$index] ?? null;

            if ($humidity === null) {
                $humidity = $hourlyHumidityByDate[$date] ?? null;
            }

            if ($humidity === null) {
                continue;
            }

            WeatherLog::updateOrCreate(
                [
                    'timestamp' => Carbon::parse($date)->startOfDay(),
                    'source' => 'archive_daily',
                ],
                [
                    'temperature' => $temperatures[$index],
                    'humidity' => $humidity,
                ],
            );

            $written++;
        }

        return $written;
    }

    /**
     * Tagesmittel der Luftfeuchtigkeit aus Stundenwerten, falls die Archiv-API
     * relative_humidity_2m_mean als Tageswert nicht anbietet.
     *
     * @param array{time?: array<int, string>, relative_humidity_2m?: array<int, float|null>} $hourly
     * @return array<string, float>
     */
    private function groupHourlyHumidityByDate(array $hourly): array
    {
        $times = $hourly['time'] ?? [];
        $values = $hourly['relative_humidity_2m'] ?? [];

        $byDate = [];

        foreach ($times as $index => $time) {
            if (! isset($values[$index])) {
                continue;
            }

            $date = substr($time, 0, 10);
            $byDate[$date][] = $values[$index];
        }

        return array_map(
            fn (array $dayValues) => array_sum($dayValues) / count($dayValues),
            $byDate,
        );
    }
}
