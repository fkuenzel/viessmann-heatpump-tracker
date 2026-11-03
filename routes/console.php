<?php

use App\Services\ViessmannSyncService;
use App\Services\WeatherSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule::call() statt Schedule::command() (Fahrplan-Fix 03.11.2026):
// Schedule::command() startet jeden faelligen Befehl als eigenen
// PHP-Unterprozess (proc_open). Beim ALL-INKL-Deployment wird schedule:run
// ueber den Artisan-Runner-Webcron-Endpoint innerhalb eines normalen
// Web-Requests (PHP-FPM) ausgefuehrt - und viele Shared-Hoster deaktivieren
// proc_open/exec fuer die FPM-Umgebung aus Sicherheitsgruenden (CLI-SSH
// bleibt davon unberuehrt). Folge: jeder Lauf schlug mit Exit-Code 255 fehl,
// obwohl "php artisan viessmann:sync" manuell per SSH einwandfrei lief.
// Schedule::call() ruft die Services direkt im selben Prozess auf - exakt
// das Muster, das App\Filament\Pages\SyncControl fuer den manuellen
// "Sync jetzt"-Button bereits verwendet (daher dort nie aufgefallen) - und
// braucht kein proc_open. ->name() ist bei call() Pflicht, da
// withoutOverlapping() den Mutex-Schluessel sonst nicht ableiten kann (bei
// command() dient dazu automatisch der Befehls-String).

// Polling-Frequenz: 15 Minuten (Phase-0-Entscheidung, siehe Fahrplan Abschnitt 6).
// Laeuft nur, wenn der Scheduler tatsaechlich aktiv ist (lokaler Betrieb ohne
// Dauer-Server) - die day[]-Nachhol-Funktion der Viessmann-API faengt
// Ausfallzeiten bis zu 8 Tagen rueckwirkend ab (siehe Abschnitt 4.2).
Schedule::call(function () {
    app(ViessmannSyncService::class)->sync('scheduled');
})
    ->name('viessmann-sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::call(function () {
    $service = app(WeatherSyncService::class);

    // Kein Wetter-Standort hinterlegt (z. B. direkt nach der Installation,
    // vor der Einrichtung unter "Einstellungen") - stiller Skip statt
    // Fehler, analog zu App\Console\Commands\SyncWeatherData.
    if (! $service->isLocationConfigured()) {
        return;
    }

    $service->sync();
})
    ->name('weather-sync')
    ->hourly()
    ->withoutOverlapping();

// Taeglicher Archiv-Nachtrag (Fabians Entscheidung, 30.10.2026): holt nachts
// den soeben abgeschlossenen Vortag aus Open-Meteos Historical-Weather-API
// nach (WeatherSyncService::backfillArchive(), Seite "Temperaturvergleich") -
// robuster als die reinen live_snapshot-Zeilen aus weather:sync, da
// unabhaengig davon, ob der Scheduler zum jeweiligen Zeitpunkt lief.
Schedule::call(function () {
    $service = app(WeatherSyncService::class);

    if (! $service->isLocationConfigured()) {
        return;
    }

    $yesterday = now()->subDay()->startOfDay();
    $service->backfillArchive($yesterday, $yesterday);
})
    ->name('weather-backfill-archive')
    ->dailyAt('01:00')
    ->withoutOverlapping();
