<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Absicherung des MCP-Endpoints (POST /mcp, Version 1.1.0, Fahrplan
 * Abschnitt 9.1): statischer Bearer-Token aus MCP_API_TOKEN. Da der Zugriff
 * rein lesend ist, genügt ein einzelner Token ohne Rollenlogik.
 *
 * Verhalten:
 *   - Kein Token konfiguriert oder kürzer als 32 Zeichen: Endpoint ist
 *     deaktiviert (404), analog zum ArtisanRunnerController. Ein zu kurzer
 *     Token gilt als Fehlkonfiguration und wird nicht stillschweigend
 *     akzeptiert.
 *   - Produktion ohne HTTPS: 403, damit der Token nie unverschlüsselt über
 *     die Leitung geht (lokal mit Herd/http gilt die Prüfung nicht).
 *   - Fehlender oder falscher Token: 401. Der Token wird ausschließlich im
 *     Authorization-Header akzeptiert, nie als Query-Parameter, damit er
 *     nicht in Zugriffs-Logs oder Referrern landet.
 *
 * Der Token ist bewusst ein anderer als ARTISAN_RUNNER_TOKEN: ein
 * kompromittierter MCP-Zugriff soll keinen Zugriff auf den Scheduler-Ersatz
 * (Schreibzugriff) ermöglichen.
 */
class AuthenticateMcpToken
{
    public const MIN_TOKEN_LENGTH = 32;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('services.mcp.token');

        if ($expectedToken === '') {
            return response()->json(['message' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        if (strlen($expectedToken) < self::MIN_TOKEN_LENGTH) {
            Log::error('MCP-Endpoint deaktiviert: MCP_API_TOKEN ist kürzer als ' . self::MIN_TOKEN_LENGTH . ' Zeichen.');

            return response()->json(['message' => 'Not Found'], Response::HTTP_NOT_FOUND);
        }

        if (app()->isProduction() && ! $request->isSecure()) {
            return response()->json(['message' => 'HTTPS erforderlich.'], Response::HTTP_FORBIDDEN);
        }

        $providedToken = (string) $request->bearerToken();

        if ($providedToken === '' || ! hash_equals($expectedToken, $providedToken)) {
            Log::warning('MCP-Endpoint: ungültiger oder fehlender Token.', ['ip' => $request->ip()]);

            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
