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
        $service->sync();

        $this->info('Wetter-Sync abgeschlossen.');

        return self::SUCCESS;
    }
}
