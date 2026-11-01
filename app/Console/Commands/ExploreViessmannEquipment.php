<?php

namespace App\Console\Commands;

use App\Services\ViessmannApiClient;
use Illuminate\Console\Command;

/**
 * Listet die Rohdaten des konfigurierten Geraets ueber den Equipment-Endpoint
 * auf (nicht den Features-Endpoint) - Produktname/-typ/Seriennummer stehen
 * dort, nicht in den Features (Fabians Wunsch 30.10.2026: Sidebar-Box mit
 * Geraete-Stammdaten, live aus der API, read-only in settings gecacht).
 *
 * Schreibt die volle Rohantwort zusaetzlich nach
 * storage/app/viessmann-equipment.json, damit die Feldnamen auch
 * nachtraeglich einsehbar sind.
 */
class ExploreViessmannEquipment extends Command
{
    protected $signature = 'viessmann:explore-equipment';

    protected $description = 'Rohdaten des Equipment-Endpoints (Geraete-Stammdaten wie Produktname/-typ/Seriennummer) anzeigen';

    public function handle(ViessmannApiClient $client): int
    {
        $data = $client->getRawEquipmentDevices();

        $path = storage_path('app/viessmann-equipment.json');
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Vollstaendige Rohdaten gespeichert unter: ' . $path);
        $this->newLine();

        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
