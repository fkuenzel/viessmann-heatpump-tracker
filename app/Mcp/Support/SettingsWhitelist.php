<?php

namespace App\Mcp\Support;

/**
 * Einstellungen, die per MCP gelesen und geaendert werden duerfen. Bewusst
 * eine Positivliste: Zugangsdaten, OAuth-Tokens, Verschluesselungsschluessel
 * und der Mail-Empfaenger (Exfiltrationsweg fuer Reports) sind ausgeschlossen,
 * ebenso der Wetter-Standort (braucht Geocoding ueber die Einstellungsseite).
 */
final class SettingsWhitelist
{
    /**
     * @return array<string, array{rules: array<int, string>, label: string, default: mixed}>
     */
    public static function all(): array
    {
        return [
            'meter_number' => ['rules' => ['nullable', 'string', 'max:255'], 'label' => 'Aktuelle Zählernummer', 'default' => null],
            'forecast_horizon_days' => ['rules' => ['integer', 'between:1,16'], 'label' => 'Wetterprognose-Horizont (Tage)', 'default' => 3],
            'billing_year_start' => ['rules' => ['nullable', 'date_format:Y-m-d'], 'label' => 'Beginn Abrechnungsjahr', 'default' => null],
            'plan_kwh' => ['rules' => ['nullable', 'numeric', 'min:0', 'max:100000'], 'label' => 'Geplanter Jahresverbrauch (kWh)', 'default' => null],
            'advance_monthly_eur' => ['rules' => ['nullable', 'numeric', 'min:0', 'max:10000'], 'label' => 'Monatlicher Abschlag (EUR)', 'default' => null],
            'report_frequency' => ['rules' => ['in:off,weekly,monthly'], 'label' => 'Report-Häufigkeit', 'default' => 'weekly'],
            'anomaly_threshold_percent' => ['rules' => ['numeric', 'between:1,500'], 'label' => 'Anomalie-Schwelle (%)', 'default' => 30],
            'anomaly_lookback_days' => ['rules' => ['integer', 'between:1,365'], 'label' => 'Anomalie-Rückblick (Tage)', 'default' => 14],
            'sync_outage_threshold_hours' => ['rules' => ['integer', 'between:1,720'], 'label' => 'Sync-Ausfall-Schwelle (Stunden)', 'default' => 24],
            'device_status_sync_interval_hours' => ['rules' => ['integer', 'between:0,168'], 'label' => 'Gerätestatus-Historisierung (Stunden, 0 = aus)', 'default' => 0],
        ];
    }

    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
