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
 * (kostenlos, kein API-Key) fuer den konfigurierten Standort (Kleve).
 */
class WeatherSyncService
{
    public function sync(): void
    {
        $horizonDays = (int) Setting::get('forecast_horizon_days', 3);
        $horizonDays = max(1, min($horizonDays, 16));

        $response = Http::get('https://api.open-meteo.com/v1/forecast', [
            'latitude' => config('weather.latitude'),
            'longitude' => config('weather.longitude'),
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
}
