<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\AddAnnotation;
use App\Mcp\Tools\AddMeterReading;
use App\Mcp\Tools\AddPlantValues;
use App\Mcp\Tools\AddTariff;
use App\Mcp\Tools\GetAuditLog;
use App\Mcp\Tools\TriggerSync;
use App\Mcp\Tools\UpdateSetting;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Schreibender MCP-Server (Release 2.0.0): alle Lese-Tools des
 * HeatpumpServer plus Tools, die ausschliesslich Daten innerhalb der
 * Anwendung aendern. Eigener Endpoint (/mcp/write) mit eigenem Token und
 * Audit-Log. Es gibt kein Tool, das die Waermepumpe steuert, und keines,
 * das Zugangsdaten oder Tokens liest oder schreibt.
 */
#[Name('Viessmann Heatpump Tracker (schreibend)')]
#[Version('2.0.0')]
#[Instructions(<<<'TEXT'
Dieser Server stellt die Messdaten einer Viessmann-Wärmepumpe rein lesend bereit (Stromverbrauch, thermische Energie, COP, Zählerstände, Kompressor, Wetter, Heizstab, Tarife, Einstellungen). Dieser Endpoint kann zusätzlich Daten in der Anwendung ändern (siehe unten). Er steuert die Wärmepumpe nicht und liest oder schreibt keine Zugangsdaten.

Wichtige Grundlagen für die Interpretation:
- "App"-Werte stammen aus der Viessmann-API (entspricht der ViCare-App). "Real"- bzw. "Zähler"-Werte basieren auf den manuell erfassten Zählerständen (HT/NT). Der Korrekturfaktor ist Zählerverbrauch geteilt durch App-Verbrauch; die App liegt typischerweise einige Prozent unter dem Zähler. Reale Tageswerte sind eine Hochrechnung (App-Tageswert mal Korrekturfaktor des Ablese-Intervalls), echte Genauigkeit gibt es nur pro Ablese-Intervall.
- Der heutige Tag ist unvollständig. Tageswerte für heute immer als "bisher" kennzeichnen und nicht mit abgeschlossenen Tagen vergleichen.
- Thermische Energie, Außentemperatur der Anlage und Kompressorzähler liefert die Viessmann-API nur als Momentaufnahme des laufenden Tages. Für Tage ohne Sync oder manuellen Nachtrag fehlen sie. Ein null-Wert bedeutet "keine Daten", niemals 0.
- COP = thermische Energie geteilt durch Stromverbrauch. Bei Ablese-Intervallen unter einem Tag ist der echte COP nicht aussagekräftig (reliable=false). Der erste Betriebstag nach der Inbetriebnahme ist üblicherweise ein Ausreißer (Erstbefüllung, initiales Aufheizen).
- Alle Energiewerte in kWh, Temperaturen in °C, Daten im Format JJJJ-MM-TT, Zeitzone der Anlage (Europe/Berlin).
- Kosten sind nur vorhanden, wenn ein Stromtarif hinterlegt ist; sonst null.
- Zeitraum-Kennzahlen (get_period_report) folgen demselben Modell wie das Dashboard: App-Werte tagesgenau, Zählerwerte nur aus vollständig enthaltenen Ablese-Intervallen mit Abdeckung (coverage_label). Summen zweier Zeiträume nur vergleichen, wenn beide gleich lang und zu mindestens 80 Prozent belegt sind; sonst Tageswerte, COP und kWh je Heizgradtag (witterungsbereinigt) vergleichen.
- Notizen (get_annotations) enthalten manuelle Einträge und automatisch erfasste Einstellungsänderungen (z. B. Heizkurve, Zeitprogramme). Bei auffälligen Verbrauchs- oder COP-Änderungen lohnt ein Blick darauf, ob kurz davor etwas an der Anlage geändert wurde.

Schreibende Tools (nur dieser Endpoint):
- Ändern nur Daten innerhalb der Anwendung: Zählerstände erfassen, Notizen und Tarife anlegen, Einstellungen aus einer Positivliste ändern, Anlagenwerte vergangener Tage nachtragen, einen Datenabruf auslösen (frühestens alle 5 Minuten). Löschen ist nur in der Oberfläche möglich.
- Jede Schreibaktion wird mit Eingabe sowie Zustand vorher und nachher protokolliert (get_audit_log).
- Vor dem Schreiben Werte (Zählerstand, Tarifpreise, Zeitpunkte) mit dem Nutzer abstimmen, wenn sie nicht ausdrücklich genannt wurden. Nichts schätzen oder erfinden. Die Antworten enthalten Plausibilitätshinweise, die dem Nutzer weitergegeben werden sollen.
TEXT)]
class HeatpumpWriteServer extends Server
{
    protected array $tools = [
        ...HeatpumpServer::READ_TOOLS,
        GetAuditLog::class,
        AddMeterReading::class,
        AddAnnotation::class,
        AddTariff::class,
        UpdateSetting::class,
        AddPlantValues::class,
        TriggerSync::class,
    ];
}
