<?php

namespace App\Services;

use App\Models\MeterReading;
use Illuminate\Support\Carbon;

/**
 * Vergleicht die reale Zaehler-Differenz zwischen zwei Ablesungen mit der
 * ueber die Viessmann-API ermittelten Verbrauchssumme im selben Zeitraum
 * (proportionale Rand-Intervall-Methode, siehe Fahrplan Abschnitt 6).
 */
class MeterCorrectionService
{
    public function __construct(private readonly ApiLogAggregationService $aggregation)
    {
    }

    /**
     * @return array{
     *     meter_kwh: float,
     *     api_kwh: ?float,
     *     correction_factor: ?float,
     *     difference_percent: ?float,
     *     missing_dates: array<string>,
     * }
     */
    public function calculate(MeterReading $from, MeterReading $to): array
    {
        $meterKwh = ((float) $to->counter_ht - (float) $from->counter_ht)
            + ((float) $to->counter_nt - (float) $from->counter_nt);

        $apiResult = $this->aggregation->weightedSum($from->timestamp, $to->timestamp, 'power_total_kwh');
        $apiKwh = $apiResult['value'];

        $correctionFactor = ($apiKwh !== null && $apiKwh > 0) ? $meterKwh / $apiKwh : null;
        $differencePercent = $correctionFactor !== null ? ($correctionFactor - 1) * 100 : null;

        return [
            'meter_kwh' => round($meterKwh, 3),
            'api_kwh' => $apiKwh !== null ? round($apiKwh, 3) : null,
            'correction_factor' => $correctionFactor !== null ? round($correctionFactor, 4) : null,
            'difference_percent' => $differencePercent !== null ? round($differencePercent, 2) : null,
            'missing_dates' => $apiResult['missing_dates'],
        ];
    }

    /**
     * Korrekturfaktor (meter_kwh / api_kwh) je Ablese-Intervall, als
     * Zeitstrahl von Kalendertag-Bereichen. Grundlage fuer die taegliche
     * "Real"-Linie in den Chart-Widgets (ConsumptionChartWidget,
     * CopChartWidget, Fahrplan Abschnitt 4.22): der reale Verbrauch liegt
     * nur als Intervall-Summe vor, der taegliche Naeherungswert wird ueber
     * den Korrekturfaktor des jeweils umschliessenden Intervalls aus dem
     * App-Tageswert hochgerechnet (Fabians Entscheidung, 29.10.2026).
     *
     * @return array<int, array{from: Carbon, to: Carbon, factor: ?float}>
     */
    public function intervalFactors(): array
    {
        $readings = MeterReading::query()->orderBy('timestamp')->get();

        $intervals = [];

        foreach ($readings as $index => $reading) {
            if ($index === 0) {
                continue;
            }

            $previous = $readings[$index - 1];
            $result = $this->calculate($previous, $reading);

            $intervals[] = [
                'from' => $previous->timestamp->copy()->startOfDay(),
                'to' => $reading->timestamp->copy()->startOfDay(),
                'factor' => $result['correction_factor'],
            ];
        }

        return $intervals;
    }

    /**
     * Korrekturfaktor fuer einen Kalendertag aus einem vorab mit
     * intervalFactors() ermittelten Zeitstrahl - Tage vor der ersten oder
     * nach der letzten Ablesung (kein umschliessendes Intervall) liefern
     * null, die taegliche "Real"-Linie zeigt dort bewusst eine Luecke statt
     * einer Schaetzung (Fabians Entscheidung, 29.10.2026).
     *
     * @param array<int, array{from: Carbon, to: Carbon, factor: ?float}> $intervals
     */
    public function factorForDate(Carbon $date, array $intervals): ?float
    {
        $date = $date->copy()->startOfDay();

        foreach ($intervals as $interval) {
            if ($date->greaterThanOrEqualTo($interval['from']) && $date->lessThanOrEqualTo($interval['to'])) {
                return $interval['factor'];
            }
        }

        return null;
    }
}
