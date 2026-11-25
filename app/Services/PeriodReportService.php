<?php

namespace App\Services;

use App\Models\MeterReading;
use App\Models\PriceHistory;
use App\Models\WeatherLog;
use App\Support\ReportPeriod;
use Illuminate\Support\Carbon;

/**
 * Kennzahlen fuer einen beliebigen Zeitraum (Release 2.0.0) - gemeinsame
 * Grundlage fuer Dashboard-Kacheln, Auswertungsseiten und MCP.
 *
 * Zwei Datenwelten, bewusst getrennt ausgewiesen:
 *
 * - App-Werte (Strom aus der API, Thermik, Kompressor, Heizstab) sind tages-
 *   genau und werden fuer jeden Tag des Zeitraums summiert.
 * - Zaehler-Werte (Verbrauch, Kosten, echter COP) gibt es nur pro Ablese-
 *   Intervall. In den Zeitraum zaehlen ausschliesslich Intervalle, die
 *   vollstaendig darin liegen; "meter.coverage_days" sagt, wie viele Tage des
 *   Zeitraums damit abgedeckt sind (z. B. 6 von 7).
 */
class PeriodReportService
{
    /** Heizgrenze und Raumtemperatur fuer Heizgradtage (VDI 3807, Gradtagzahl 20/15). */
    public const HEATING_LIMIT_TEMP = 15.0;

    public const ROOM_TEMP = 20.0;

    public function __construct(
        private readonly ApiLogAggregationService $aggregation,
        private readonly MeterCorrectionService $correction,
        private readonly CostCalculationService $cost,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function report(ReportPeriod $period): array
    {
        $now = Carbon::now();
        $lastDay = $period->to->lessThan($now->copy()->startOfDay()) ? $period->to->copy() : $now->copy()->startOfDay();

        $power = ['total' => 0.0, 'heating' => 0.0, 'dhw' => 0.0];
        $powerDays = 0;
        $thermal = ['total' => 0.0, 'heating' => 0.0, 'dhw' => 0.0];
        $thermalDays = 0;
        $copPower = 0.0;
        $copThermal = 0.0;
        $copDays = 0;
        $heatingRodKwh = 0.0;
        $heatingRodDays = 0;
        $tempSum = 0.0;
        $tempDays = 0;
        $degreeDays = 0.0;
        $degreeDayHeatingKwh = 0.0;
        $starts = 0.0;
        $startsDays = 0;
        $hours = 0.0;
        $hoursDays = 0;

        $cursor = $period->from->copy();

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $dayTotal = $this->aggregation->dailyPowerValue($cursor, 'power_total_kwh');
            $dayHeating = $this->aggregation->dailyPowerValue($cursor, 'power_heating_kwh');
            $dayDhw = $this->aggregation->dailyPowerValue($cursor, 'power_dhw_kwh');

            if ($dayTotal !== null) {
                $power['total'] += $dayTotal;
                $powerDays++;
            }

            $power['heating'] += $dayHeating ?? 0.0;
            $power['dhw'] += $dayDhw ?? 0.0;

            $tHeating = $this->aggregation->lastSnapshotValue($cursor, 'thermal_heating_kwh');
            $tDhw = $this->aggregation->lastSnapshotValue($cursor, 'thermal_dhw_kwh');
            $dayThermal = ($tHeating === null && $tDhw === null) ? null : ($tHeating ?? 0.0) + ($tDhw ?? 0.0);

            if ($dayThermal !== null) {
                $thermal['total'] += $dayThermal;
                $thermal['heating'] += $tHeating ?? 0.0;
                $thermal['dhw'] += $tDhw ?? 0.0;
                $thermalDays++;
            }

            if ($dayTotal !== null && $dayThermal !== null && $dayTotal > 0) {
                $copPower += $dayTotal;
                $copThermal += $dayThermal;
                $copDays++;
            }

            $rodHeating = $this->aggregation->lastSnapshotValue($cursor, 'heating_rod_heating_kwh');
            $rodDhw = $this->aggregation->lastSnapshotValue($cursor, 'heating_rod_dhw_kwh');

            if ($rodHeating !== null || $rodDhw !== null) {
                $heatingRodKwh += ($rodHeating ?? 0.0) + ($rodDhw ?? 0.0);
                $heatingRodDays++;
            }

            $temp = $this->averageTemperature($cursor);

            if ($temp !== null) {
                $tempSum += $temp;
                $tempDays++;

                if ($temp < self::HEATING_LIMIT_TEMP) {
                    $dayDegreeDays = self::ROOM_TEMP - $temp;
                    $degreeDays += $dayDegreeDays;
                    $degreeDayHeatingKwh += $dayHeating ?? 0.0;
                }
            }

            $startsDelta = $this->counterDelta($cursor, 'compressor_starts');

            if ($startsDelta !== null) {
                $starts += $startsDelta;
                $startsDays++;
            }

            $hoursDelta = $this->counterDelta($cursor, 'compressor_hours');

            if ($hoursDelta !== null) {
                $hours += $hoursDelta;
                $hoursDays++;
            }

            $cursor->addDay();
        }

        $totalDays = (int) $period->from->diffInDays($lastDay) + 1;
        $meter = $this->meterBlock($period, $now, $totalDays);

        return [
            'period' => [
                'from' => $period->from->toDateString(),
                'to' => $period->to->toDateString(),
                'days' => $totalDays,
                'includes_today' => $period->includesToday($now),
            ],
            'app' => [
                'power_total_kwh' => $powerDays > 0 ? round($power['total'], 2) : null,
                'power_heating_kwh' => $powerDays > 0 ? round($power['heating'], 2) : null,
                'power_dhw_kwh' => $powerDays > 0 ? round($power['dhw'], 2) : null,
                'power_avg_per_day_kwh' => $powerDays > 0 ? round($power['total'] / $powerDays, 2) : null,
                'power_days' => $powerDays,
                'thermal_total_kwh' => $thermalDays > 0 ? round($thermal['total'], 2) : null,
                'thermal_heating_kwh' => $thermalDays > 0 ? round($thermal['heating'], 2) : null,
                'thermal_dhw_kwh' => $thermalDays > 0 ? round($thermal['dhw'], 2) : null,
                'thermal_days' => $thermalDays,
                'cop' => $copPower > 0 ? round($copThermal / $copPower, 2) : null,
                'cop_days' => $copDays,
            ],
            'meter' => $meter,
            'weather' => [
                'avg_temperature_celsius' => $tempDays > 0 ? round($tempSum / $tempDays, 1) : null,
                'temperature_days' => $tempDays,
                'heating_degree_days' => $tempDays > 0 ? round($degreeDays, 1) : null,
                'heating_kwh_per_degree_day' => $degreeDays > 0 ? round($degreeDayHeatingKwh / $degreeDays, 2) : null,
            ],
            'compressor' => [
                'starts' => $startsDays > 0 ? (int) round($starts) : null,
                'hours' => $hoursDays > 0 ? round($hours, 1) : null,
                'days' => max($startsDays, $hoursDays),
            ],
            'heating_rod' => [
                'kwh' => $heatingRodDays > 0 ? round($heatingRodKwh, 2) : null,
                'days' => $heatingRodDays,
            ],
        ];
    }

    /**
     * Zeitraum plus Vergleichszeitraum samt Differenzen.
     *
     * @return array{current: array<string, mixed>, previous: ?array<string, mixed>, deltas: ?array<string, array<string, mixed>>}
     */
    public function reportWithComparison(ReportPeriod $period): array
    {
        $current = $this->report($period);
        $comparison = $period->comparison();

        if ($comparison === null) {
            return ['current' => $current, 'previous' => null, 'deltas' => null];
        }

        $previous = $this->report($comparison);

        return [
            'current' => $current,
            'previous' => $previous,
            'deltas' => $this->deltas($current, $previous),
        ];
    }

    /**
     * Absolute und prozentuale Aenderung aller numerischen Kennzahlen, die
     * in beiden Berichten vorhanden sind (Abschnitte app/meter/weather/
     * compressor/heating_rod). Zaehl-Hilfsfelder ("*_days") entfallen.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, array{value: float, previous: float, delta: float, delta_percent: ?float}>
     */
    public function deltas(array $current, array $previous): array
    {
        $result = [];

        foreach (['app', 'meter', 'weather', 'compressor', 'heating_rod'] as $section) {
            foreach ($current[$section] ?? [] as $key => $value) {
                $before = $previous[$section][$key] ?? null;

                if (! is_numeric($value) || ! is_numeric($before) || str_ends_with($key, '_days') || $key === 'days') {
                    continue;
                }

                $delta = (float) $value - (float) $before;

                $result[$section . '.' . $key] = [
                    'value' => (float) $value,
                    'previous' => (float) $before,
                    'delta' => round($delta, 2),
                    'delta_percent' => (float) $before != 0.0 ? round($delta / abs((float) $before) * 100, 1) : null,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function meterBlock(ReportPeriod $period, Carbon $now, int $totalDays): array
    {
        $windowStart = $period->from->copy()->startOfDay();
        $windowEnd = $period->to->copy()->endOfDay();

        $meterKwh = 0.0;
        $apiKwh = 0.0;
        $costTotal = 0.0;
        $hasCost = false;
        $thermalKwh = 0.0;
        $hasThermal = false;
        $coveredDays = 0.0;
        $intervals = 0;
        $estimated = 0;
        $tariffChanged = false;

        foreach (MeterReading::intervalPairs() as [$from, $to]) {
            if ($from->timestamp->lessThan($windowStart) || $to->timestamp->greaterThan($windowEnd)) {
                continue;
            }

            $result = $this->correction->calculate($from, $to);
            $meterKwh += $result['meter_kwh'];
            $apiKwh += $result['api_kwh'] ?? 0.0;
            $coveredDays += $from->timestamp->diffInSeconds($to->timestamp) / 86400;
            $intervals++;

            if (MeterReading::pairInvolvesEstimate($from, $to)) {
                $estimated++;
            }

            $costResult = $this->cost->calculate($from, $to);

            if ($costResult['total_cost'] !== null) {
                $costTotal += $costResult['total_cost'];
                $hasCost = true;
            }

            $tariffChanged = $tariffChanged || $costResult['tariff_changed_during_interval'];

            $thermal = $this->aggregation->weightedThermalSum($from->timestamp, $to->timestamp)['value'];

            if ($thermal !== null) {
                $thermalKwh += $thermal;
                $hasThermal = true;
            }
        }

        $hasData = $intervals > 0;
        $coverageRatio = $totalDays > 0 ? min(1.0, $coveredDays / $totalDays) : 0.0;

        return [
            'consumption_kwh' => $hasData ? round($meterKwh, 2) : null,
            'app_kwh_same_intervals' => $hasData ? round($apiKwh, 2) : null,
            'correction_factor' => ($hasData && $apiKwh > 0) ? round($meterKwh / $apiKwh, 4) : null,
            'cost_eur' => ($hasData && $hasCost) ? round($costTotal, 2) : null,
            // Hochrechnung auf den ganzen Zeitraum, nur bei Teilabdeckung ab 50 %.
            'cost_extrapolated_eur' => ($hasData && $hasCost && $coverageRatio >= 0.5 && $coverageRatio < 1.0)
                ? round($costTotal / $coverageRatio, 2)
                : null,
            'thermal_kwh' => ($hasData && $hasThermal) ? round($thermalKwh, 2) : null,
            'cop' => ($hasData && $hasThermal && $meterKwh > 0) ? round($thermalKwh / $meterKwh, 2) : null,
            'coverage_days' => round($coveredDays, 1),
            'coverage_ratio' => round($coverageRatio, 3),
            'coverage_label' => $hasData
                ? sprintf('%s von %d Tagen mit Zählerwerten', rtrim(rtrim(number_format($coveredDays, 1, ',', ''), '0'), ','), $totalDays)
                : 'Keine vollständige Zähler-Ablesung im Zeitraum',
            'intervals' => $intervals,
            'estimated_intervals' => $estimated,
            'tariff_changed_during_interval' => $tariffChanged,
            'has_tariff' => PriceHistory::query()->exists(),
        ];
    }

    public function averageTemperature(Carbon $date): ?float
    {
        $plant = $this->aggregation->averageOutdoorTemp($date);

        if ($plant !== null) {
            return $plant;
        }

        $day = WeatherLog::query()
            ->whereBetween('timestamp', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->avg('temperature');

        return $day !== null ? (float) $day : null;
    }

    /**
     * Zuwachs eines kumulierten Zaehlers (Kompressor-Starts/-Stunden) an einem Tag.
     */
    public function counterDelta(Carbon $date, string $column): ?float
    {
        $current = $this->aggregation->lastSnapshotValue($date, $column);
        $before = $this->aggregation->lastSnapshotValue($date->copy()->subDay(), $column);

        return ($current === null || $before === null) ? null : max(0.0, $current - $before);
    }
}
