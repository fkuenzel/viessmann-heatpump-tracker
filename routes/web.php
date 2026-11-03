<?php

use App\Http\Controllers\ArtisanRunnerController;
use App\Http\Controllers\ViessmannOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->prefix('viessmann')->group(function () {
    Route::get('/connect', [ViessmannOAuthController::class, 'connect'])->name('viessmann.connect');
    Route::get('/callback', [ViessmannOAuthController::class, 'callback'])->name('viessmann.callback');
});

// Ersatz fuer SSH/CLI-Scheduler und einmalige Artisan-Befehle auf Webspace
// ohne Shell-Zugriff (z. B. ALL-INKL, Fahrplan-Entscheidung 02.11.2026).
// Absicherung: Token (siehe ArtisanRunnerController) + optional zusaetzlich
// HTTP-Basic-Auth, serverseitig beim Cronjob-Provider hinterlegt - niemals
// nur ueber die URL-Geheimhaltung absichern.
Route::get('/system/run/{command}', [ArtisanRunnerController::class, 'run'])
    ->name('system.run');
