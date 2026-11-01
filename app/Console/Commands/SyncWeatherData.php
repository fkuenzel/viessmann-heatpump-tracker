<?php

namespace App\Console\Commands;

use App\Services\WeatherSyncService;
use Illuminate\Console\Command;

class SyncWeatherData extends Command
{
    protected $signature = 'weather:sync';

    protected $description = 'Aktuelle Wetterdaten und Prognose von Open-Meteo abrufen';

    public function handle(WeatherSyncService $service): int
    {
        if (! $service->isLocationConfigured()) {
            // Still ueberspringen statt Fehler - typischer Fall direkt nach
            // der Installation, bevor ein Wetter-Standort hinterlegt wurde
            // (Github-Fahrplan Abschnitt 4). Kein Log-Fehler, da das kein
            // Ausfall ist, sondern ein erwarteter Zustand.
            $this->info('Kein Wetter-Standort konfiguriert - Sync übersprungen.');

            return self::SUCCESS;
        }

        $service->sync();

        $this->info('Wetter-Sync abgeschlossen.');

        return self::SUCCESS;
    }
}
