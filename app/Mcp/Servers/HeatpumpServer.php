<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CheckAnomalies;
use App\Mcp\Tools\GetAnnotations;
use App\Mcp\Tools\GetCompressorStats;
use App\Mcp\Tools\GetCopTrend;
use App\Mcp\Tools\GetCurrentStatus;
use App\Mcp\Tools\GetDailySummary;
use App\Mcp\Tools\GetIntervalBreakdown;
use App\Mcp\Tools\GetWeatherComparison;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * MCP-Server des Viessmann Heatpump Trackers (Version 1.1.0, Fahrplan
 * Abschnitt 9): stellt die erfassten Anlagendaten rein lesend für MCP-Clients
 * wie Claude bereit. Alle Tools greifen auf dieselben Services zu wie die
 * Filament-Widgets, liefern also dieselben Werte wie das Dashboard.
 */
#[Name('Viessmann Heatpump Tracker')]
#[Version('1.2.1')]
#[Instructions(<<<'TEXT'
Dieser Server stellt die Messdaten einer Viessmann-Wärmepumpe rein lesend bereit (Stromverbrauch, thermische Energie, COP, Zählerstände, Kompressor, Wetter). Es gibt keine schreibenden Tools.

Wichtige Grundlagen für die Interpretation:
- "App"-Werte stammen aus der Viessmann-API (entspricht der ViCare-App). "Real"- bzw. "Zähler"-Werte basieren auf den manuell erfassten Zählerständen (HT/NT). Der Korrekturfaktor ist Zählerverbrauch geteilt durch App-Verbrauch; die App liegt typischerweise einige Prozent unter dem Zähler. Reale Tageswerte sind eine Hochrechnung (App-Tageswert mal Korrekturfaktor des Ablese-Intervalls), echte Genauigkeit gibt es nur pro Ablese-Intervall.
- Der heutige Tag ist unvollständig. Tageswerte für heute immer als "bisher" kennzeichnen und nicht mit abgeschlossenen Tagen vergleichen.
- Thermische Energie, Außentemperatur der Anlage und Kompressorzähler liefert die Viessmann-API nur als Momentaufnahme des laufenden Tages. Für Tage ohne Sync oder manuellen Nachtrag fehlen sie. Ein null-Wert bedeutet "keine Daten", niemals 0.
- COP = thermische Energie geteilt durch Stromverbrauch. Bei Ablese-Intervallen unter einem Tag ist der echte COP nicht aussagekräftig (reliable=false). Der erste Betriebstag nach der Inbetriebnahme ist üblicherweise ein Ausreißer (Erstbefüllung, initiales Aufheizen).
- Alle Energiewerte in kWh, Temperaturen in °C, Daten im Format JJJJ-MM-TT, Zeitzone der Anlage (Europe/Berlin).
- Kosten sind nur vorhanden, wenn ein Stromtarif hinterlegt ist; sonst null.
- Notizen (get_annotations) enthalten manuelle Einträge und automatisch erfasste Einstellungsänderungen (z. B. Heizkurve, Zeitprogramme). Bei auffälligen Verbrauchs- oder COP-Änderungen lohnt ein Blick darauf, ob kurz davor etwas an der Anlage geändert wurde.
TEXT)]
class HeatpumpServer extends Server
{
    protected array $tools = [
        GetCurrentStatus::class,
        GetDailySummary::class,
        GetIntervalBreakdown::class,
        GetCopTrend::class,
        CheckAnomalies::class,
        GetCompressorStats::class,
        GetWeatherComparison::class,
        GetAnnotations::class,
    ];
}
