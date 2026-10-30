<?php

namespace App\Console\Commands;

use App\Services\ViessmannApiClient;
use Illuminate\Console\Command;

/**
 * Testet die EventHistory-v2-API (Export + Installation Events) als
 * moeglichen alternativen Weg an Thermik-/Kompressor-Historie zu kommen,
 * fuer die es kein day[]-Array gibt (Fahrplan Abschnitt 4.10/14). Von
 * Fabian in der Viessmann-API-Doku gefunden (28.10.2026).
 *
 * Schreibt alle Rohantworten nach storage/app/ zur gemeinsamen Auswertung.
 */
class ExploreViessmannEvents extends Command
{
    protected $signature = 'viessmann:explore-events {--days=14 : lastNDays fuer die Events-Abfrage} {--skip-export : Export-Job-Test auslassen}';

    protected $description = 'EventHistory-v2 API testen (feature-changed-Events + Export-Job) als Alternative zur fehlenden Thermik-Tageshistorie';

    public function handle(ViessmannApiClient $client): int
    {
        $this->testFeatureChangedEvents($client);
        $this->newLine();

        if (! $this->option('skip-export')) {
            $this->testExport($client);
        }

        return self::SUCCESS;
    }

    private function testFeatureChangedEvents(ViessmannApiClient $client): void
    {
        $this->info('--- Installation Events (eventType=feature-changed) ---');

        $query = [
            'eventType' => 'feature-changed',
            'gatewaySerials' => config('viessmann.gateway_serial'),
            'lastNDays' => (string) $this->option('days'),
            'limit' => '1000',
        ];

        try {
            $result = $client->getInstallationEvents($query);
        } catch (\Throwable $e) {
            $this->error('Fehlgeschlagen: ' . $e->getMessage());

            return;
        }

        $path = storage_path('app/viessmann-events-feature-changed.json');
        file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Rohdaten gespeichert unter: ' . $path);

        $events = $result['data'] ?? [];
        $this->info('Anzahl Events (diese Seite): ' . count($events));

        if (! empty($events)) {
            $this->line('Beispiel-Event (erstes Element):');
            $this->line(json_encode($events[0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $featureNames = collect($events)
                ->pluck('eventData.feature')
                ->filter()
                ->unique()
                ->values();

            if ($featureNames->isNotEmpty()) {
                $this->newLine();
                $this->info('Vorkommende Feature-Namen in den Events:');
                $featureNames->each(fn ($name) => $this->line('  - ' . $name));
            }
        }

        if (! empty($result['cursor']['next'] ?? null)) {
            $this->warn('Es gibt weitere Seiten (cursor.next vorhanden) - fuer eine vollstaendige Auswertung ggf. nachladen.');
        }
    }

    private function testExport(ViessmannApiClient $client): void
    {
        $this->info('--- Export-Job (EventHistory-v2 Export) ---');

        try {
            $processId = $client->initExport();
        } catch (\Throwable $e) {
            $this->error('Export-Start fehlgeschlagen: ' . $e->getMessage());

            return;
        }

        $this->info('Export-Job gestartet, processId: ' . $processId);
        $this->info('Warte 5 Sekunden und hole das Ergebnis ab ...');
        sleep(5);

        try {
            $result = $client->getExportResult($processId);
        } catch (\Throwable $e) {
            $this->error('Abruf des Export-Ergebnisses fehlgeschlagen (ggf. noch nicht fertig, Befehl einfach spaeter erneut mit --skip-export auslassen und Export separat pruefen): ' . $e->getMessage());

            return;
        }

        $path = storage_path('app/viessmann-export-result.json');
        file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Rohdaten gespeichert unter: ' . $path);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
