<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Ersatz fuer SSH/CLI-Zugriff auf dem ALL-INKL-Webspace (Fahrplan-
 * Entscheidung 02.11.2026): ALL-INKL bietet dort nur FTP und Cronjobs als
 * HTTPS-URL, kein Composer, kein "php artisan" ueber Shell. Diese Route
 * fuehrt stattdessen Artisan::call() fuer eine feste Whitelist von
 * Befehlen aus und ersetzt so sowohl den Scheduler-Tick
 * ("schedule-run", per Webcron alle 5 Minuten) als auch die gelegentlichen
 * manuellen Befehle (migrate, cache:clear etc.), die sonst per CLI liefen.
 *
 * Absicherung in zwei Schichten (Fahrplan 02.11.2026, "Artisan-Runner-
 * Absicherung"):
 *   1. Dieser Controller: Token-Pruefung per hash_equals() gegen
 *      ARTISAN_RUNNER_TOKEN aus der .env.
 *   2. Zusaetzlich im ALL-INKL-KAS bei der Cronjob-Einrichtung: HTTP-
 *      Basic-Auth (Benutzer/Passwort), siehe README/Deployment-Doku.
 * Ohne beide Faktoren bleibt der Request wirkungslos, auch wenn die URL
 * durch Logs, Referrer oder automatisiertes Scannen bekannt wird.
 *
 * Nur eine feste Whitelist von Befehlen ist erreichbar - kein generischer
 * Shell- oder Artisan-Zugriff von aussen.
 */
class ArtisanRunnerController extends Controller
{
    /**
     * Erlaubte Befehle: Routenname => [Artisan-Befehl, Parameter].
     *
     * schedule-run laeuft per Webcron alle 5 Minuten (kleinster bei
     * ALL-INKL sinnvoll nutzbarer Takt, siehe Fahrplan Abschnitt 6) und
     * triggert darueber viessmann:sync (alle 15 Minuten), weather:sync
     * (stuendlich) und weather:backfill-archive (taeglich 01:00) - Laravel
     * entscheidet selbst anhand von routes/console.php, welche Jobs faellig
     * sind.
     */
    private const ALLOWED_COMMANDS = [
        'schedule-run' => ['schedule:run', []],
        'db-backup' => ['db:backup', []],
        'migrate' => ['migrate', ['--force' => true]],
        'config-clear' => ['config:clear', []],
        'cache-clear' => ['cache:clear', []],
        'storage-link' => ['storage:link', []],
        'weather-backfill-archive' => ['weather:backfill-archive', []],
    ];

    public function run(Request $request, string $command): Response
    {
        $expectedToken = config('services.artisan_runner.token');

        if (empty($expectedToken)) {
            // Kein Token konfiguriert = Endpoint ist absichtlich deaktiviert.
            Log::warning('Artisan-Runner aufgerufen, aber ARTISAN_RUNNER_TOKEN ist nicht gesetzt.');

            return response('Not Found', SymfonyResponse::HTTP_NOT_FOUND);
        }

        $providedToken = (string) $request->query('token', '');

        if (! hash_equals($expectedToken, $providedToken)) {
            Log::warning('Artisan-Runner: ungueltiger Token.', ['command' => $command, 'ip' => $request->ip()]);

            return response('Unauthorized', SymfonyResponse::HTTP_UNAUTHORIZED);
        }

        if (! array_key_exists($command, self::ALLOWED_COMMANDS)) {
            return response('Unknown command', SymfonyResponse::HTTP_NOT_FOUND);
        }

        [$artisanCommand, $parameters] = self::ALLOWED_COMMANDS[$command];

        $exitCode = Artisan::call($artisanCommand, $parameters);
        $output = Artisan::output();

        Log::info("Artisan-Runner: {$artisanCommand} ausgefuehrt.", [
            'exit_code' => $exitCode,
            'command' => $command,
        ]);

        return response($output, $exitCode === 0 ? SymfonyResponse::HTTP_OK : SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR)
            ->header('Content-Type', 'text/plain');
    }
}
