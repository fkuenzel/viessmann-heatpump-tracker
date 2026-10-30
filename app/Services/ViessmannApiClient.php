<?php

namespace App\Services;

use App\Exceptions\ViessmannApiException;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OAuth2 Authorization-Code+PKCE-Client fuer die Viessmann IoT API.
 *
 * Token-Speicherung erfolgt ueber die settings-Tabelle (Single-User-Betrieb,
 * kein eigenes Token-Schema notwendig). Der PKCE code_verifier und der
 * state-Parameter werden waehrend des Authorize-Redirects kurzlebig in der
 * Session gehalten.
 */
class ViessmannApiClient
{
    private const SETTING_ACCESS_TOKEN = 'viessmann_access_token';

    private const SETTING_REFRESH_TOKEN = 'viessmann_refresh_token';

    private const SETTING_EXPIRES_AT = 'viessmann_token_expires_at';

    private const SESSION_VERIFIER = 'viessmann_pkce_verifier';

    private const SESSION_STATE = 'viessmann_pkce_state';

    /**
     * Sicherheitspuffer, bevor der Access-Token offiziell ablaeuft.
     */
    private const EXPIRY_BUFFER_SECONDS = 60;

    public function isConnected(): bool
    {
        return filled(Setting::get(self::SETTING_REFRESH_TOKEN));
    }

    /**
     * Authorize-URL fuer den PKCE-Flow erzeugen und code_verifier/state
     * fuer den anschliessenden Callback in der Session ablegen.
     */
    public function getAuthorizationUrl(): string
    {
        $verifier = Str::random(64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $state = Str::random(32);

        session([
            self::SESSION_VERIFIER => $verifier,
            self::SESSION_STATE => $state,
        ]);

        $query = http_build_query([
            'client_id' => config('viessmann.client_id'),
            'redirect_uri' => config('viessmann.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('viessmann.scope'),
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => $state,
        ]);

        return config('viessmann.auth_base') . '/authorize?' . $query;
    }

    /**
     * Callback nach dem Authorize-Redirect verarbeiten: state pruefen,
     * code gegen Tokens eintauschen, Tokens speichern.
     */
    public function handleCallback(string $code, string $state): void
    {
        $expectedState = session(self::SESSION_STATE);
        $verifier = session(self::SESSION_VERIFIER);

        if (! $expectedState || ! hash_equals($expectedState, $state)) {
            throw new ViessmannApiException('Ungueltiger state-Parameter beim Viessmann-OAuth-Callback.');
        }

        if (! $verifier) {
            throw new ViessmannApiException('Kein PKCE code_verifier in der Session gefunden. Flow bitte erneut starten.');
        }

        $response = Http::asForm()->post(config('viessmann.auth_base') . '/token', [
            'grant_type' => 'authorization_code',
            'client_id' => config('viessmann.client_id'),
            'redirect_uri' => config('viessmann.redirect_uri'),
            'code' => $code,
            'code_verifier' => $verifier,
        ]);

        if ($response->failed()) {
            throw new ViessmannApiException('Token-Austausch fehlgeschlagen: ' . $response->body());
        }

        $this->storeTokenResponse($response->json());

        session()->forget([self::SESSION_VERIFIER, self::SESSION_STATE]);
    }

    /**
     * Gueltigen Access-Token liefern, erneuert ihn bei Bedarf automatisch
     * ueber den Refresh-Token.
     */
    public function getAccessToken(): string
    {
        $accessToken = Setting::get(self::SETTING_ACCESS_TOKEN);
        $expiresAt = Setting::get(self::SETTING_EXPIRES_AT);

        if ($accessToken && $expiresAt && now()->lt(\Carbon\Carbon::parse($expiresAt)->subSeconds(self::EXPIRY_BUFFER_SECONDS))) {
            return $accessToken;
        }

        return $this->refreshAccessToken();
    }

    public function refreshAccessToken(): string
    {
        $refreshToken = Setting::get(self::SETTING_REFRESH_TOKEN);

        if (! $refreshToken) {
            throw new ViessmannApiException('Kein Refresh-Token vorhanden. Bitte die Viessmann-Verbindung einmalig ueber den Authorize-Flow autorisieren.');
        }

        $response = Http::asForm()->post(config('viessmann.auth_base') . '/token', [
            'grant_type' => 'refresh_token',
            'client_id' => config('viessmann.client_id'),
            'refresh_token' => $refreshToken,
        ]);

        if ($response->failed()) {
            throw new ViessmannApiException('Token-Refresh fehlgeschlagen: ' . $response->body());
        }

        $this->storeTokenResponse($response->json());

        return Setting::get(self::SETTING_ACCESS_TOKEN);
    }

    /**
     * Authentifizierten GET-Request gegen ein Feature des konfigurierten
     * Geraets ausfuehren, z. B. featureName = "heating.power.consumption.total".
     */
    public function getFeature(string $featureName): array
    {
        $uri = sprintf(
            '/features/installations/%s/gateways/%s/devices/%s/features/%s',
            config('viessmann.installation_id'),
            config('viessmann.gateway_serial'),
            config('viessmann.device_id'),
            $featureName
        );

        $response = Http::withToken($this->getAccessToken())
            ->get(config('viessmann.api_base') . $uri);

        if ($response->failed()) {
            throw new ViessmannApiException("Abruf von Feature [{$featureName}] fehlgeschlagen: " . $response->body());
        }

        return $response->json('data', []);
    }

    /**
     * Alle Features des konfigurierten Geraets abrufen (ohne konkreten
     * Feature-Namen in der URL) - fuer die Suche nach bisher nicht
     * ausprobierten Features, die vielleicht doch eine Tages-Historie
     * fuer thermische Energie o.ae. liefern (Fahrplan Abschnitt 4.10/14).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listFeatures(): array
    {
        $uri = sprintf(
            '/features/installations/%s/gateways/%s/devices/%s/features',
            config('viessmann.installation_id'),
            config('viessmann.gateway_serial'),
            config('viessmann.device_id'),
        );

        $response = Http::withToken($this->getAccessToken())
            ->get(config('viessmann.api_base') . $uri);

        if ($response->failed()) {
            throw new ViessmannApiException('Abruf der vollstaendigen Feature-Liste fehlgeschlagen: ' . $response->body());
        }

        return $response->json('data', []);
    }

    /**
     * Installation-Events abfragen (EventHistory-v2, siehe
     * https://api.viessmann-climatesolutions.com/documentation/services/EventHistory-v2).
     * Insbesondere eventType=feature-changed koennte rueckwirkend
     * Wertaenderungen von Features protokollieren, fuer die es kein
     * day[]-Array gibt (Fahrplan Abschnitt 4.10/14) - z.B. Thermik,
     * Aussentemperatur, Kompressor-Statistik.
     *
     * @param  array<string, scalar>  $query  z.B. ['eventType' => 'feature-changed', 'lastNDays' => '7', 'gatewaySerials' => '...', 'limit' => '1000']
     * @return array<string, mixed>
     */
    public function getInstallationEvents(array $query = []): array
    {
        $uri = sprintf(
            '/eventhistory/installations/%s/events',
            config('viessmann.installation_id'),
        );

        $response = Http::withToken($this->getAccessToken())
            ->get(config('viessmann.api_base') . $uri, $query);

        if ($response->failed()) {
            throw new ViessmannApiException('Abruf der Installation-Events fehlgeschlagen: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Export-Job starten (EventHistory-v2 Export). Liefert eine Process-ID,
     * mit der das Ergebnis anschliessend ueber getExportResult() abgeholt
     * werden kann.
     */
    public function initExport(): string
    {
        $uri = sprintf(
            '/eventhistory/installations/%s/export',
            config('viessmann.installation_id'),
        );

        $response = Http::withToken($this->getAccessToken())
            ->post(config('viessmann.api_base') . $uri);

        if ($response->failed()) {
            throw new ViessmannApiException('Export-Start fehlgeschlagen: ' . $response->body());
        }

        return $response->json('data');
    }

    /**
     * Ergebnis eines zuvor gestarteten Export-Jobs abholen.
     *
     * @return array<string, mixed>
     */
    public function getExportResult(string $processId): array
    {
        $uri = sprintf(
            '/eventhistory/installations/%s/export/%s',
            config('viessmann.installation_id'),
            $processId,
        );

        $response = Http::withToken($this->getAccessToken())
            ->get(config('viessmann.api_base') . $uri);

        if ($response->failed()) {
            throw new ViessmannApiException('Abruf des Export-Ergebnisses fehlgeschlagen: ' . $response->body());
        }

        return $response->json();
    }

    private function storeTokenResponse(array $data): void
    {
        if (! isset($data['access_token'])) {
            throw new ViessmannApiException('Token-Antwort enthielt keinen access_token: ' . json_encode($data));
        }

        Setting::set(self::SETTING_ACCESS_TOKEN, $data['access_token']);
        Setting::set(self::SETTING_EXPIRES_AT, now()->addSeconds((int) ($data['expires_in'] ?? 3600))->toIso8601String());

        if (isset($data['refresh_token'])) {
            Setting::set(self::SETTING_REFRESH_TOKEN, $data['refresh_token']);
        }
    }
}
