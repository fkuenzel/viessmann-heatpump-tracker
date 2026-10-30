<?php

namespace App\Console\Commands;

use App\Services\ViessmannApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Listet ALLE Features des Geraets auf (nicht nur die bisher einzeln
 * ausprobierten), um systematisch zu pruefen, ob es doch eine Tages-
 * Historie fuer thermische Energie, Kompressor o.ae. gibt, die wir bislang
 * uebersehen haben (Fahrplan Abschnitt 4.10, Nachfrage Fabian 28.10.2026).
 *
 * Schreibt zusaetzlich die komplette Rohantwort nach
 * storage/app/viessmann-features.json, damit die volle Struktur auch
 * nachtraeglich einsehbar ist (die Konsolen-Ausgabe zeigt nur eine
 * Kurzfassung je Feature).
 */
class ExploreViessmannFeatures extends Command
{
    protected $signature = 'viessmann:explore-features {--filter= : Nur Features, deren Name diesen Teilstring enthaelt}';

    protected $description = 'Alle verfuegbaren Viessmann-Features auflisten und ihre Struktur anzeigen (Suche nach uebersehenen Tages-Historien)';

    public function handle(ViessmannApiClient $client): int
    {
        $features = $client->listFeatures();

        $path = storage_path('app/viessmann-features.json');
        file_put_contents($path, json_encode($features, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Vollstaendige Rohdaten gespeichert unter: ' . $path);
        $this->newLine();

        $filter = $this->option('filter');
        $interesting = ['heat', 'production', 'thermal', 'compressor', 'burner', 'energy', 'spf', 'scop', 'statistics'];

        $this->info('Gesamtzahl Features: ' . count($features));
        $this->newLine();

        $shown = 0;

        foreach ($features as $feature) {
            $name = $feature['feature'] ?? 'unbekannt';

            $matchesFilter = $filter
                ? Str::contains($name, $filter, ignoreCase: true)
                : Str::contains($name, $interesting, ignoreCase: true);

            if (! $matchesFilter) {
                continue;
            }

            $shown++;
            $isEnabled = ($feature['isEnabled'] ?? false) ? 'ja' : 'nein';
            $properties = $feature['properties'] ?? [];

            $this->line("<fg=yellow>{$name}</> (isEnabled: {$isEnabled})");

            foreach ($properties as $propName => $propData) {
                $value = $propData['value'] ?? null;
                $type = $propData['type'] ?? '?';

                if (is_array($value)) {
                    $preview = json_encode(array_slice($value, 0, 5));
                    $this->line("    {$propName} ({$type}, Array mit " . count($value) . ' Einträgen): ' . $preview);
                } else {
                    $this->line("    {$propName} ({$type}): " . json_encode($value));
                }
            }

            $this->newLine();
        }

        if ($shown === 0) {
            $this->warn('Keine Features gefunden, die zum Filter passen.');
        }

        return self::SUCCESS;
    }
}
