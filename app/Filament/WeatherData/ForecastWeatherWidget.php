<?php

namespace App\Filament\WeatherData;

use App\Models\Setting;
use App\Models\WeatherForecastLog;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Kurzfrist-Prognose (Horizont aus Setting::forecast_horizon_days) - bewusst
 * als eigenes, optisch getrenntes Widget unter CurrentWeatherWidget (Fabians
 * Wunsch, 30.10.2026: "Aktuell" und "Prognose" getrennt statt in einer
 * gemeinsamen Kachelreihe wie urspruenglich im alten WeatherWidget).
 */
class ForecastWeatherWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Prognose';

    protected function getStats(): array
    {
        $horizonDays = (int) Setting::get('forecast_horizon_days', 3);

        $forecast = WeatherForecastLog::query()
            ->where('forecast_for', '>=', now()->startOfDay())
            ->orderByDesc('fetched_at')
            ->get()
            ->unique('forecast_for')
            ->sortBy('forecast_for')
            ->take($horizonDays);

        $stats = [];

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
