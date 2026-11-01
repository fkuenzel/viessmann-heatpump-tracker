<?php

namespace App\Services;

use App\Exceptions\ExternalApiException;
use Illuminate\Support\Facades\Http;

/**
 * Loest einen vom Nutzer eingegebenen Ortsnamen ueber Open-Meteos
 * kostenlose Geocoding-API in Koordinaten auf - fuer die SettingsPage,
 * damit der Wetter-Standort als Ortsname statt als rohe Koordinaten
 * gepflegt werden kann (Github-Fahrplan Abschnitt 3, Fabians Entscheidung:
 * Ortsname mit Geocoding statt reiner Koordinaten-Eingabe).
 */
class GeocodingService
{
    /**
     * @return array{name: string, latitude: float, longitude: float}|null null, wenn kein Treffer gefunden wurde
     */
    public function resolve(string $locationName): ?array
    {
        $response = Http::get('https://geocoding-api.open-meteo.com/v1/search', [
            'name' => $locationName,
            'count' => 1,
            'language' => 'de',
            'format' => 'json',
        ]);

        if ($response->failed()) {
            throw new ExternalApiException('Open-Meteo-Geocoding-Abruf fehlgeschlagen: ' . $response->body());
        }

        $result = $response->json('results.0');

        if (! $result) {
            return null;
        }

        $name = $result['name'];

        if (! empty($result['admin1']) && $result['admin1'] !== $name) {
            $name .= ', ' . $result['admin1'];
        }

        if (! empty($result['country'])) {
            $name .= ', ' . $result['country'];
        }

        return [
            'name' => $name,
            'latitude' => (float) $result['latitude'],
            'longitude' => (float) $result['longitude'],
        ];
    }
}
