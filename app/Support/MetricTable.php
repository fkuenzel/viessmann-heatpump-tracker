<?php

namespace App\Support;

/**
 * Kennzahlen-Tabelle "aktuell / Vergleich / Differenz" fuer Auswertungsseiten,
 * gespeist aus PeriodReportService-Berichten.
 */
final class MetricTable
{
    /**
     * Abschnitt.Schluessel => [Bezeichnung, Einheit, Nachkommastellen]
     */
    public const METRICS = [
        'app.power_total_kwh' => ['Strom gesamt (App)', 'kWh', 1],
        'app.power_heating_kwh' => ['Strom Heizen (App)', 'kWh', 1],
        'app.power_dhw_kwh' => ['Strom Warmwasser (App)', 'kWh', 1],
        'app.power_avg_per_day_kwh' => ['Strom je Tag (App)', 'kWh', 1],
        'app.thermal_total_kwh' => ['Wärme gesamt (App)', 'kWh', 1],
        'app.cop' => ['COP (App)', '', 2],
        'meter.consumption_kwh' => ['Verbrauch (Zähler)', 'kWh', 1],
        'meter.cost_eur' => ['Kosten (Zähler)', '€', 2],
        'meter.cop' => ['COP (Zähler)', '', 2],
        'weather.avg_temperature_celsius' => ['Außentemperatur Ø', '°C', 1],
        'weather.heating_degree_days' => ['Heizgradtage', '', 0],
        'weather.heating_kwh_per_degree_day' => ['Heizen je Heizgradtag (witterungsbereinigt)', 'kWh', 2],
        'compressor.starts' => ['Verdichterstarts', '', 0],
        'compressor.hours' => ['Verdichter-Laufzeit', 'h', 0],
        'heating_rod.kwh' => ['Heizstab', 'kWh', 2],
    ];

    /** Summenkennzahlen: nur vergleichbar, wenn beide Zeiträume gleich lang und ausreichend belegt sind. */
    private const SUMS = [
        'app.power_total_kwh', 'app.power_heating_kwh', 'app.power_dhw_kwh', 'app.thermal_total_kwh',
        'meter.consumption_kwh', 'meter.cost_eur', 'weather.heating_degree_days',
        'compressor.starts', 'compressor.hours', 'heating_rod.kwh',
    ];

    /**
     * Hinweis, warum Summen nicht verglichen werden (null = vergleichbar).
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     */
    public static function sumsNote(array $current, array $previous): ?string
    {
        if ($previous === []) {
            return null;
        }

        $daysA = (int) data_get($current, 'period.days', 0);
        $daysB = (int) data_get($previous, 'period.days', 0);

        if ($daysA !== $daysB) {
            return "Die Zeiträume sind unterschiedlich lang ({$daysA} und {$daysB} Tage): Summen werden nicht verglichen, Tageswerte und Verhältniszahlen schon.";
        }

        foreach ([['aktuellen', $current, $daysA], ['Vergleichs-', $previous, $daysB]] as [$name, $report, $days]) {
            $have = (int) data_get($report, 'app.power_days', 0);

            if ($days > 0 && $have / $days < 0.8) {
                return "Der {$name}zeitraum ist nur an {$have} von {$days} Tagen mit Daten belegt: Summen werden nicht verglichen, Tageswerte und Verhältniszahlen schon.";
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<int, array<string, string>>
     */
    public static function rows(array $current, array $previous): array
    {
        $rows = [];
        $sumsBlocked = self::sumsNote($current, $previous) !== null;

        foreach (self::METRICS as $path => [$label, $unit, $decimals]) {
            $value = data_get($current, $path);
            $before = data_get($previous, $path);

            if ($value === null && $before === null) {
                continue;
            }

            $blocked = $sumsBlocked && in_array($path, self::SUMS, true);
            $delta = (! $blocked && is_numeric($value) && is_numeric($before)) ? (float) $value - (float) $before : null;
            $percent = ($delta !== null && (float) $before != 0.0) ? $delta / abs((float) $before) * 100 : null;
            $suffix = $unit !== '' ? ' ' . $unit : '';

            $rows[] = [
                'label' => $label,
                'current' => self::fmt($value, $decimals) . ($value !== null ? $suffix : ''),
                'previous' => self::fmt($before, $decimals) . ($before !== null ? $suffix : ''),
                'delta' => $delta === null ? '–' : ($delta >= 0 ? '+' : '') . number_format($delta, $decimals, ',', '.') . $suffix,
                'percent' => $percent === null ? '–' : ($percent >= 0 ? '+' : '') . number_format($percent, 1, ',', '.') . ' %',
            ];
        }

        return $rows;
    }

    public static function fmt(mixed $value, int $decimals): string
    {
        return is_numeric($value) ? number_format((float) $value, $decimals, ',', '.') : '–';
    }
}
