<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Macht den Wetter-Standort konfigurierbar statt fest auf Kleve im Code
 * verdrahtet zu sein (Github-Fahrplan Abschnitt 3, hohe Priorität - "muss
 * vor einer sinnvollen Veröffentlichung umgesetzt werden", da jeder
 * künftige Betreiber einen anderen Standort fuer seine Anlage hat).
 *
 * Uebernimmt einmalig Fabians bisherigen, hartcodierten Standort
 * (config/weather.php, WEATHER_LATITUDE/WEATHER_LONGITUDE) als initialen
 * Settings-Wert, damit der laufende Betrieb nicht unterbrochen wird. Eine
 * frische Installation (ohne gesetzte WEATHER_LATITUDE/LONGITUDE in .env)
 * startet dagegen bewusst ohne Standort - WeatherSyncService ueberspringt
 * den Sync dann, bis der Standort ueber die Einstellungen-Seite per
 * Ortsname + Geocoding hinterlegt wird (siehe GeocodingService,
 * SettingsPage, Fahrplan Abschnitt 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Setting::get('weather_latitude') !== null) {
            return;
        }

        $latitude = config('weather.latitude');
        $longitude = config('weather.longitude');

        if ($latitude === null || $longitude === null) {
            return;
        }

        Setting::set('weather_location_name', 'Kleve');
        Setting::set('weather_latitude', (float) $latitude);
        Setting::set('weather_longitude', (float) $longitude);
    }

    public function down(): void
    {
        Setting::set('weather_location_name', null);
        Setting::set('weather_latitude', null);
        Setting::set('weather_longitude', null);
    }
};
