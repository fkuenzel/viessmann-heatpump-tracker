<?php

namespace App\Console\Commands;

use App\Services\ViessmannSyncService;
use Illuminate\Console\Command;

class SyncViessmannData extends Command
{
    protected $signature = 'viessmann:sync {--manual : Als manuell ausgeloest kennzeichnen (sonst "scheduled")}';

    protected $description = 'Consumption-/Production-Daten von der Viessmann-API abrufen und in api_log schreiben';

    public function handle(ViessmannSyncService $service): int
    {
        $source = $this->option('manual') ? 'manual' : 'scheduled';

        $written = $service->sync($source);

        $this->info("Viessmann-Sync abgeschlossen ({$source}): {$written} Zeilen in api_log geschrieben.");

        return self::SUCCESS;
    }
}
