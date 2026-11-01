<?php

namespace App\Console\Commands;

use App\Services\WeatherSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Backfill/Nachtrag echter Tagesmittelwerte aus Open-Meteos Historical-
 * Weather-API (Fahrplan-Entscheidung 30.10.2026: Archiv-API statt reiner
 * Snapshot-Aggregation, siehe WeatherSyncService::backfillArchive()).
 *
 * Ohne Optionen: nur der Vortag - das ist der naechtliche Scheduler-Fall
 * (routes/console.php), der taeglich den gerade abgeschlossenen Tag
 * nachtraegt, sobald die Archiv-API ihn final hat.
 *
 * Mit --from/--to: einmaliger initialer Backfill ueber einen groesseren
 * Zeitraum, z. B. seit Inbetriebnahme der Anlage:
 *   php artisan weather:backfill-archive --from=2026-10-21 --to=yesterday
 *
 * Ohne hinterlegten Wetter-Standort (frische Installation vor der
 * Einrichtung) wird der Lauf uebersprungen statt mit einem Fehler
 * abzubrechen (Github-Fahrplan Abschnitt 4).
 */
class BackfillWeatherArchive extends Command
{
    protected $signature = 'weather:backfill-archive {--from=} {--to=}';

    protected $description = 'Echte Tagesmittel-Wetterdaten aus Open-Meteos Archiv-API nachtragen (Standard: nur gestern)';

    public function handle(WeatherSyncService $service): int
    {
        if (! $service->isLocationConfigured()) {
            $this->info('Kein Wetter-Standort konfiguriert - Archiv-Nachtrag übersprungen.');

            return self::SUCCESS;
        }

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfDay()
            : now()->subDay()->startOfDay();

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->startOfDay()
            : now()->subDay()->startOfDay();

        if ($from->greaterThan($to)) {
            $this->error('--from darf nicht nach --to liegen.');

            return self::FAILURE;
        }

        $written = $service->backfillArchive($from, $to);

        $this->info("{$written} Tag(e) aus dem Wetter-Archiv nachgetragen ({$from->toDateString()} bis {$to->toDateString()}).");

        return self::SUCCESS;
    }
}
