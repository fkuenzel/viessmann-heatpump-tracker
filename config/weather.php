<?php

/*
 * WEATHER_LATITUDE/WEATHER_LONGITUDE bewusst OHNE hartcodierten Default-Wert
 * (Github-Fahrplan Abschnitt 3/4): eine frische Installation soll ohne
 * gesetzte .env-Werte auch ohne Standort starten, nicht automatisch einen
 * Beispielstandort uebernehmen. Der eigentliche, vom Betreiber genutzte
 * Standort wird ueber die Einstellungen-Seite per Ortsname + Geocoding
 * gepflegt und in der settings-Tabelle gespeichert (siehe
 * App\Services\GeocodingService, App\Filament\Pages\SettingsPage) - diese
 * Config-Werte werden nur noch von der einmaligen Seed-Migration
 * (2026_10_31_120000_seed_weather_location_settings.php) gelesen, um
 * Fabians bisherigen, bereits in Betrieb befindlichen Standort zu
 * uebernehmen.
 */
return [
    'latitude' => env('WEATHER_LATITUDE'),
    'longitude' => env('WEATHER_LONGITUDE'),
];
