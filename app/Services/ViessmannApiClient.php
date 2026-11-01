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
     * Rohdaten des Equipment-Endpoints fuer das konfigurierte Geraet abrufen
     * (Produktname/-typ/Seriennummer stehen hier, nicht in den Features -
     * Fabians Wunsch 30.10.2026, Sidebar-Box mit Geraete-Stammdaten).
     * Bewusst ungemappt/roh, um beim erstmaligen Einrichten per
     * viessmann:explore-equipment die tatsaechlichen Feldnamen zu sehen,
     * bevor getDeviceInfo() ein festes Mapping darauf aufbaut.
     *
     * @return array<string, mixed>
     */
    public function getRawEquipmentDevices(): array
    {
        $uri = sprintf(
            '/equipment/installations/%s/gateways/%s/devices',
            config('viessmann.installation_id'),
            config('viessmann.gateway_serial'),
        );

        $response = Http::withToken($this->getAccessToken())
            ->get(config('viessmann.api_base') . $uri);

        if ($response->failed()) {
            throw new ViessmannApiException('Abruf der Equipment-Geraeteliste fehlgeschlagen: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Alle Installationen des Accounts abrufen - fuer den Install-Wizard
     * (php artisan app:install, Github-Fahrplan Abschnitt 2), der
     * Installation-ID, Gateway-Serial und Device-ID automatisch ermitteln
     * soll, statt sie den Nutzer manuell per API-Request nachschlagen zu
     * lassen (die Werte stehen nirgendwo sichtbar im Viessmann-Portal).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInstallations(): array
    {
        $response = Http::withToken($this->getAccessToken())
            ->get(config('viessmann.api_base') . '/equipment/installations');

        if ($response->failed()) {
            throw new ViessmannApiException('Abruf der Installationsliste fehlgeschlagen: ' . $response->body());
        }

        return $response->json('data', []);
    }

    /**
     * Gateways einer Installation abrufen (Install-Wizard, siehe
     * listInstallations()).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listGateways(string $installationId): array
    {
        $uri = sprintf('/equipment/installations/%s/gateways', $installationId);

        $response = Http::withToken($this->getAccessToken())
            ->get(config('viessmann.api_base') . $uri);

        if ($response->failed()) {
            throw new ViessmannApiException('Abruf der Gateway-Liste fehlgeschlagen: ' . $response->body());
        }

        return $response->json('data', []);
    }

    /**
     * Geraete eines Gateways abrufen (Install-Wizard, siehe
     * listInstallations()). Im Gegensatz zu getRawEquipmentDevices() ohne
     * Rueckgriff auf die config-Werte, da Installation-ID/Gateway-Serial
     * im Install-Wizard zu diesem Zeitpunkt noch nicht in der .env stehen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDevices(string $installationId, string $gatewaySerial): array
    {
        $uri = sprintf('/equipment/installations/%s/gateways/%s/devices', $installationId, $gatewaySerial);

        $response = Http::withToken($this->getAccessToken())
            ->get(config('viessmann.api_base') . $uri);

        if ($response->failed()) {
            throw new ViessmannApiException('Abruf der Geräteliste fehlgeschlagen: ' . $response->body());
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

    /**
     * Aktuelle Geraetestatus-Werte in einem Rutsch abrufen (analog zur
     * ViCare-App-Seite "Geraetestatus", Fabians Wunsch 30.10.2026). Nutzt
     * listFeatures() statt acht einzelner getFeature()-Aufrufe, um bei
     * gleicher Aktualitaet nur einen API-Request zu brauchen - sowohl fuer
     * die Live-Seite als auch fuer den Sync (ViessmannSyncService) genutzt.
     *
     * Feature-Mapping siehe Migration
     * 2026_10_30_180000_add_device_status_fields_to_api_log_table.php.
     *
     * Enthaelt auch compressor_starts/compressor_hours (identisch zu
     * compressorStatistics()-Aufruf in ViessmannSyncService) - werden hier
     * im selben listFeatures()-Request mitgelesen statt per zusaetzlichem
     * getFeature()-Aufruf, um pro Sync einen API-Request zu sparen.
     *
     * @return array{
     *     flow_temp: ?float,
     *     return_temp: ?float,
     *     system_pressure: ?float,
     *     internal_pump_status: ?string,
     *     volumetric_flow: ?float,
     *     secondary_flow_temp: ?float,
     *     compressor_active: ?bool,
     *     compressor_phase: ?string,
     *     compressor_starts: ?int,
     *     compressor_hours: ?float,
     * }
     */
    public function getDeviceStatus(): array
    {
        $features = collect($this->listFeatures())->keyBy('feature');

        $value = function (string $featureName, string $property = 'value') use ($features) {
            return $features->get($featureName)['properties'][$property]['value'] ?? null;
        };

        $starts = $value('heating.compressors.0.statistics', 'starts');
        $hours = $value('heating.compressors.0.statistics', 'hours');

        return [
            'flow_temp' => $this->toFloat($value('heating.boiler.sensors.temperature.commonSupply')),
            'return_temp' => $this->toFloat($value('heating.sensors.temperature.return')),
            'system_pressure' => $this->toFloat($value('heating.sensors.pressure.supply')),
            'internal_pump_status' => $value('heating.boiler.pumps.internal', 'status'),
            'volumetric_flow' => $this->toFloat($value('heating.sensors.volumetricFlow.allengra')),
            'secondary_flow_temp' => $this->toFloat($value('heating.secondaryCircuit.sensors.temperature.supply')),
            'compressor_active' => $value('heating.compressors.0', 'active'),
            'compressor_phase' => $value('heating.compressors.0', 'phase'),
            'compressor_starts' => $starts === null ? null : (int) $starts,
            'compressor_hours' => $this->toFloat($hours),
        ];
    }

    /**
     * Geraete-Stammdaten (Produktname/-typ/Seriennummer) fuer die Sidebar-Box
     * live aus dem Equipment-Endpoint ermitteln (Fabians Wunsch 30.10.2026).
     * Bestaetigt per viessmann:explore-equipment (30.10.2026): die Seriennummer
     * steht 1:1 als boilerSerial am Geraet mit deviceType=heating, der genaue
     * App-Wortlaut "IDU-A Modular AWMIW.A1.19-V051" ist dagegen NICHT in der
     * API enthalten (vermutlich ein interner Produktkatalog-Lookup der
     * ViCare-App) - stattdessen wird die Produktbezeichnung aus dem Eintrag
     * "type:product;<Name>" in den roles abgeleitet (hier z. B. "Vitocal_250A"),
     * da das die einzige live verfuegbare Modellangabe ist. Fabians
     * ausdrueckliche Entscheidung: lieber die (technisch etwas groebere)
     * Live-API-Angabe als ein statischer Text.
     *
     * @return array{name: ?string, type: ?string, serial: ?string}
     */
    public function getDeviceInfo(): array
    {
        $devices = $this->getRawEquipmentDevices()['data'] ?? [];
        $device = collect($devices)->firstWhere('deviceType', 'heating');

        if ($device === null) {
            return ['name' => null, 'type' => null, 'serial' => null];
        }

        $brand = $device['brand'] ?? 'Viessmann';
        $serial = $device['boilerSerial'] ?? null;

        $productRole = collect($device['roles'] ?? [])
            ->first(fn (string $role) => str_starts_with($role, 'type:product;'));

        $type = null;

        if ($productRole) {
            $raw = substr($productRole, strlen('type:product;'));
            $readable = str_replace('_', ' ', $raw);
            // "250A" -> "250-A" (Bindestrich vor einem einzelnen Buchstaben am Ende,
            // analog zur offiziellen Schreibweise "Vitocal 250-A").
            $type = preg_replace('/(\d)([A-Z])$/', '$1-$2', $readable);
        }

        $name = $type ? trim("{$brand} {$type}") : null;

        return [
            'name' => $name,
            'type' => $type,
            'serial' => $serial,
        ];
    }

    private function toFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
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
