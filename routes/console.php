<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Polling-Frequenz: 15 Minuten (Phase-0-Entscheidung, siehe Fahrplan Abschnitt 6).
// Laeuft nur, wenn der Scheduler tatsaechlich aktiv ist (lokaler Betrieb ohne
// Dauer-Server) - die day[]-Nachhol-Funktion der Viessmann-API faengt
// Ausfallzeiten bis zu 8 Tagen rueckwirkend ab (siehe Abschnitt 4.2).
Schedule::command('viessmann:sync')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

Schedule::command('weather:sync')
    ->hourly()
    ->withoutOverlapping();
