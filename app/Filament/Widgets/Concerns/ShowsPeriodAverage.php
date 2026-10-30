<?php

namespace App\Filament\Widgets\Concerns;

/**
 * Formatierungs-/Vergleichslogik fuer "aktueller Durchschnitt + Vergleich
 * Vorzeitraum" in Chart-Heading/-Description (Fabians Wunsch, 30.10.2026) -
 * geteilt zwischen den sieben Tages-Chart-Widgets (TotalConsumptionChartWidget
 * und Geschwister, CopChartWidget), die diese Logik sonst siebenfach
 * duplizieren muessten. Jedes Widget berechnet selbst die Werte fuer den
 * aktuellen und den vorangehenden Zeitraum (unterschiedliche Datenquellen je
 * Widget) und reicht sie an die Methoden hier zur Formatierung durch.
 *
 * Die Description wird ueber Filaments Section-Komponente automatisch klein
 * und ausgegraut dargestellt (fi-section-header-description-Klasse,
 * vendor/filament/support/.../section/description.blade.php) - keine eigene
 * CSS-Anpassung noetig.
 */
trait ShowsPeriodAverage
{
    /**
     * Durchschnitt = Summe der vorhandenen Werte / Anzahl Tage MIT Wert
     * (nicht / Gesamtanzahl Tage im Zeitraum) - sonst wuerden Luecken (z.B.
     * vor Backfill-Beginn oder Tage ohne Sync-Lauf) den Durchschnitt
     * kuenstlich nach unten ziehen.
     *
     * @param  array<int, float|null>  $values
     */
    private function periodAverage(array $values): ?float
    {
        $present = array_filter($values, fn (?float $value) => $value !== null);

        if ($present === []) {
            return null;
        }

        return array_sum($present) / count($present);
    }

    /**
     * Haengt den aktuellen Durchschnitt an die statische Heading an (z.B.
     * "Stromverbrauch Heizen · Ø 17,34 kWh/Tag"). Ohne Wert bleibt die
     * Heading unveraendert statt eines leeren "· Ø –".
     */
    private function formatAverageHeading(string $baseHeading, ?float $currentAverage, string $unitSuffix, int $decimals = 2): string
    {
        if ($currentAverage === null) {
            return $baseHeading;
        }

        $formatted = number_format($currentAverage, $decimals, ',', '.') . ($unitSuffix !== '' ? ' ' . $unitSuffix : '');

        return $baseHeading . ' · Ø ' . $formatted;
    }

    /**
     * Description-Text: Durchschnitt des unmittelbar vorangehenden, gleich
     * langen Zeitraums plus prozentuale Veraenderung gegenueber dem
     * aktuellen Durchschnitt. null, wenn fuer den Vorzeitraum keine Daten
     * vorliegen (z.B. Messbeginn liegt noch nicht lange genug zurueck) -
     * Filaments Section blendet eine leere Description automatisch aus.
     */
    private function periodComparisonDescription(?float $currentAverage, ?float $previousAverage, string $unitSuffix, int $decimals = 2): ?string
    {
        if ($previousAverage === null) {
            return null;
        }

        $previousFormatted = number_format($previousAverage, $decimals, ',', '.') . ($unitSuffix !== '' ? ' ' . $unitSuffix : '');

        if ($currentAverage === null || $previousAverage == 0.0) {
            return 'Vorzeitraum: Ø ' . $previousFormatted;
        }

        $changePercent = (($currentAverage - $previousAverage) / $previousAverage) * 100;
        $sign = $changePercent >= 0 ? '+' : '';

        return 'Vorzeitraum: Ø ' . $previousFormatted . ' (' . $sign . number_format($changePercent, 1, ',', '.') . ' %)';
    }
}
