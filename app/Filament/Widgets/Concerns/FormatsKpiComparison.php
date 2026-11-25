<?php

namespace App\Filament\Widgets\Concerns;

use App\Support\ReportPeriod;

/**
 * Gemeinsame Formatierung der Vergleichsangaben in den Dashboard-Kacheln.
 */
trait FormatsKpiComparison
{
    protected function number(?float $value, int $decimals = 2, string $suffix = ''): string
    {
        return $value === null ? '–' : number_format($value, $decimals, ',', '.') . $suffix;
    }

    /**
     * Beschreibung unter der Kachel: Veraenderung gegenueber dem
     * Vergleichszeitraum, z. B. "+12,3 % (vorher 98,40 kWh)".
     *
     * @param  bool  $higherIsBetter  true = Anstieg ist gut (COP), false = Anstieg ist schlecht (Verbrauch, Kosten)
     * @return array{text: ?string, color: string, icon: ?string}
     */
    protected function comparison(?float $current, ?float $previous, ReportPeriod $period, int $decimals = 2, string $suffix = '', bool $higherIsBetter = false): array
    {
        if (! $period->hasComparison() || $current === null || $previous === null) {
            return ['text' => $period->hasComparison() && $previous === null ? 'Kein Vergleichswert' : null, 'color' => 'gray', 'icon' => null];
        }

        if ((float) $previous === 0.0) {
            return ['text' => 'Vorher ' . $this->number($previous, $decimals, $suffix), 'color' => 'gray', 'icon' => null];
        }

        $percent = (($current - $previous) / abs($previous)) * 100;

        if (abs($percent) < 0.5) {
            return ['text' => 'unverändert (vorher ' . $this->number($previous, $decimals, $suffix) . ')', 'color' => 'gray', 'icon' => 'heroicon-m-minus'];
        }

        $up = $percent > 0;
        $good = $up === $higherIsBetter;

        return [
            'text' => ($up ? '+' : '') . number_format($percent, 1, ',', '.') . ' % (vorher ' . $this->number($previous, $decimals, $suffix) . ')',
            'color' => $good ? 'success' : 'danger',
            'icon' => $up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down',
        ];
    }
}
