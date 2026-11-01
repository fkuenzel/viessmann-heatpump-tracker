<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use Filament\Pages\Page;

/**
 * Eigene Seite fuer alles Wetterbezogene - umbenannt von "Temperaturvergleich"
 * zu "Wetterdaten" (Fabians Wunsch, 30.10.2026), nachdem das bisherige
 * Dashboard-Widget App\Filament\Widgets\WeatherWidget (aktuelle Werte +
 * Prognose) hierher verschoben wurde: die Seite ist jetzt nicht mehr nur
 * rueckblickender Vergleich, sondern auch aktuelle Werte/Prognose. Bindet
 * vier Widgets aus App\Filament\WeatherData per @livewire() direkt in die
 * View ein, statt sie als Filament-Panel-Widgets zu registrieren - genau
 * deshalb liegen alle vier Klassen bewusst ausserhalb von
 * app/Filament/Widgets/ (siehe dortige Docblocks), damit sie nicht
 * automatisch auf dem Haupt-Dashboard erscheinen:
 *   1. CurrentWeatherWidget - Anlage/Kleve nebeneinander (Fabians Wunsch)
 *   2. ForecastWeatherWidget - Prognose, optisch getrennt darunter
 *   3. TemperatureChartWidget - historischer Vergleich, Linienchart
 *   4. TemperatureTableWidget - historischer Vergleich, Tabelle pro Tag
 *
 * Keine navigationGroup gesetzt (die Fahrplan-Navigationsgruppen "Daten
 * erfassen"/"Konfiguration" passen inhaltlich nicht) - die Seite steht wie
 * das Dashboard selbst ungruppiert oben in der Sidebar.
 *
 * Ohne hinterlegten Wetter-Standort (Setting weather_latitude/weather_
 * longitude, siehe App\Filament\Pages\SettingsPage) komplett aus der
 * Navigation ausgeblendet statt nur leer angezeigt (Github-Fahrplan
 * Abschnitt 4, Vorbild App\Filament\Widgets\AnnotationsWidget::canView()) -
 * fuer eine frische Installation vor der Standort-Einrichtung sollen die
 * davon abhaengigen Werte nicht als Fehler/falsche Nullwerte erscheinen.
 * canAccess() blockiert zusaetzlich den direkten Routenaufruf, nicht nur
 * den Navigationseintrag.
 */
class WeatherData extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cloud';

    protected static ?string $navigationLabel = 'Wetterdaten';

    protected static ?string $title = 'Wetterdaten';

    protected string $view = 'filament.pages.weather-data';

    public static function shouldRegisterNavigation(): bool
    {
        return static::weatherLocationConfigured();
    }

    public static function canAccess(): bool
    {
        return static::weatherLocationConfigured();
    }

    private static function weatherLocationConfigured(): bool
    {
        return Setting::get('weather_latitude') !== null
            && Setting::get('weather_longitude') !== null;
    }
}
