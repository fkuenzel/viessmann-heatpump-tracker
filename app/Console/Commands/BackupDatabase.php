<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

/**
 * Taegliches Backup der SQLite-Produktionsdatenbank (Fahrplan-Entscheidung
 * 03.11.2026: wiederholtes Hin-und-her-Schieben der database.sqlite zwischen
 * lokal und Live ist ausgeschlossen, Live bleibt alleinige Quelle der
 * Wahrheit - stattdessen regelmaessiges, rein serverseitiges Backup als
 * Schutz gegen Datenverlust).
 *
 * Kopiert database/database.sqlite nach storage/app/backups/ mit Datums-
 * Suffix und loescht Kopien aelter als --keep-days (Default 30), damit der
 * Webspace nicht vollläuft. Wird ueber den Artisan-Runner-Endpoint per
 * Webcron angestossen (siehe routes/console.php und
 * App\Http\Controllers\ArtisanRunnerController).
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep-days=30 : Anzahl Tage, die Backups aufbewahrt werden}';

    protected $description = 'Erstellt eine Kopie der SQLite-Datenbank und entfernt veraltete Backups';

    public function handle(): int
    {
        $source = database_path('database.sqlite');

        if (! File::exists($source)) {
            $this->error("Quelldatei nicht gefunden: {$source}");

            return self::FAILURE;
        }

        $backupDir = storage_path('app/backups');
        File::ensureDirectoryExists($backupDir);

        $timestamp = Carbon::now()->format('Y-m-d_H-i');
        $destination = "{$backupDir}/database-{$timestamp}.sqlite";

        File::copy($source, $destination);
        $this->info("Backup erstellt: {$destination}");

        $keepDays = (int) $this->option('keep-days');
        $cutoff = Carbon::now()->subDays($keepDays);
        $removed = 0;

        foreach (File::glob("{$backupDir}/database-*.sqlite") as $file) {
            if (Carbon::createFromTimestamp(File::lastModified($file))->lt($cutoff)) {
                File::delete($file);
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->info("{$removed} veraltete(s) Backup(s) entfernt (aelter als {$keepDays} Tage).");
        }

        return self::SUCCESS;
    }
}
