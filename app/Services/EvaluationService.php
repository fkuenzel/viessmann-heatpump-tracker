<?php

namespace App\Services;

use App\Models\ApiLog;
use App\Models\MeterReading;
use App\Models\PriceHistory;
use App\Support\ReportPeriod;
use Illuminate\Support\Carbon;

/**
 * Tageszeitreihen und abgeleitete Auswertungen fuer die Seiten der
 * Navigationsgruppe "Auswertungen" (Release 2.0.0). Alle Werte stammen aus
 * denselben Quellen wie PeriodReportService (ApiLogAggregationService), damit
 * Dashboard und Auswertungen nie voneinander abweichen.
 */
class EvaluationService
{
    public function __construct(
        private readonly ApiLogAggregationService $aggregation,
        private readonly PeriodReportService $reports,
        private readonly MeterCorrectionService $correction,
        private readonly CostCalculationService $cost,
    ) {
    }

    /**
     * Eine Zeile je Kalendertag des Zeitraums (ohne den laufenden Tag, dessen
     * Werte unvollstaendig waeren). Fehlende Werte sind null, nie 0.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dailySeries(ReportPeriod $period): array
    {
        $rows = [];
        $cursor = $period->from->copy();
        $last = $period->chartTo();

        while ($cursor->lessThanOrEqualTo($last)) {
            $total = $this->aggregation->dailyPowerValue($cursor, 'power_total_kwh');
            $heating = $this->aggregation->dailyPowerValue($cursor, 'power_heating_kwh');
            $dhw = $this->aggregation->dailyPowerValue($cursor, 'power_dhw_kwh');
            $tHeating = $this->aggregation->lastSnapshotValue($cursor, 'thermal_heating_kwh');
            $tDhw = $this->aggregation->lastSnapshotValue($cursor, 'thermal_dhw_kwh');
            $thermal = ($tHeating === null && $tDhw === null) ? null : ($tHeating ?? 0.0) + ($tDhw ?? 0.0);
            $rodHeating = $this->aggregation->lastSnapshotValue($cursor, 'heating_rod_heating_kwh');
            $rodDhw = $this->aggregation->lastSnapshotValue($cursor, 'heating_rod_dhw_kwh');

            $rows[] = [
                'date' => $cursor->toDateString(),
                'temp' => $this->reports->averageTemperature($cursor),
                'power_total' => $total,
                'power_heating' => $heating,
                'power_dhw' => $dhw,
                'thermal' => $thermal,
                'thermal_heating' => $tHeating,
                'thermal_dhw' => $tDhw,
                'cop' => ($total !== null && $total > 0 && $thermal !== null) ? $thermal / $total : null,
                'cop_dhw' => ($dhw !== null && $dhw > 0 && $tDhw !== null) ? $tDhw / $dhw : null,
                'cop_heating' => ($heating !== null && $heating > 0 && $tHeating !== null) ? $tHeating / $heating : null,
                'starts' => $this->reports->counterDelta($cursor, 'compressor_starts'),
                'hours' => $this->reports->counterDelta($cursor, 'compressor_hours'),
                'rod_kwh' => ($rodHeating === null && $rodDhw === null) ? null : ($rodHeating ?? 0.0) + ($rodDhw ?? 0.0),
                'rod_starts' => $this->reports->counterDelta($cursor, 'heating_rod_starts'),
                'rod_hours' => $this->reports->counterDelta($cursor, 'heating_rod_hours'),
            ];

            $cursor->addDay();
        }

        return $rows;
    }

    /**
     * COP und Verbrauch nach Aussentemperatur in Klassen (Standard 2 K).
     *
     * @param  array<int, array<string, mixed>>  $series
     * @return array<int, array<string, mixed>>
     */
    public function temperatureClasses(array $series, float $width = 2.0): array
    {
        $buckets = [];

        foreach ($series as $row) {
            if ($row['temp'] === null || $row['cop'] === null) {
                continue;
            }

            $start = floor($row['temp'] / $width) * $width;
            $buckets[(string) $start][] = $row;
        }

        ksort($buckets, SORT_NUMERIC);
        $result = [];

        foreach ($buckets as $start => $rows) {
            $n = count($rows);
            $result[] = [
                'class' => sprintf('%s bis %s °C', $this->num((float) $start, 0), $this->num((float) $start + $width, 0)),
                'start' => (float) $start,
                'days' => $n,
                'avg_cop' => round(array_sum(array_column($rows, 'cop')) / $n, 2),
                'avg_power' => round(array_sum(array_column($rows, 'power_total')) / $n, 1),
                'avg_thermal' => round(array_sum(array_column($rows, 'thermal')) / $n, 1),
                'rod_kwh' => round(array_sum(array_map(fn ($r) => $r['rod_kwh'] ?? 0.0, $rows)), 2),
            ];
        }

        return $result;
    }

    /**
     * Vorher/Nachher-Vergleich um einen Zeitpunkt (z. B. eine Einstellungs-
     * aenderung). Das "Nachher"-Fenster endet spaetestens gestern.
     *
     * @return array{before: ReportPeriod, after: ReportPeriod, after_days: int, report_before: array<string, mixed>, report_after: array<string, mixed>, deltas: array<string, array<string, mixed>>}
     */
    public function changeImpact(Carbon $at, int $days): array
    {
        $day = $at->copy()->startOfDay();
        $yesterday = Carbon::now()->startOfDay()->subDay();

        $before = new ReportPeriod($day->copy()->subDays($days), $day->copy()->subDay(), 'none', 'custom');
        $afterEnd = $day->copy()->addDays($days - 1);
        $after = new ReportPeriod($day->copy(), $afterEnd->greaterThan($yesterday) ? $yesterday : $afterEnd, 'none', 'custom');

        $reportBefore = $this->reports->report($before);
        $reportAfter = $after->to->lessThan($after->from)
            ? null
            : $this->reports->report($after);

        return [
            'before' => $before,
            'after' => $after,
            'after_days' => $reportAfter === null ? 0 : $reportAfter['period']['days'],
            'report_before' => $reportBefore,
            'report_after' => $reportAfter ?? [],
            'deltas' => $reportAfter === null ? [] : $this->reports->deltas($reportAfter, $reportBefore),
        ];
    }

    /**
     * Datenqualitaet im Zeitraum: Tage ohne Werte, Sync-Luecken, Intervalle.
     *
     * @return array<string, mixed>
     */
    public function dataQuality(ReportPeriod $period, int $gapHours): array
    {
        $series = $this->dailySeries($period);
        $noPower = [];
        $noThermal = [];
        $noTemp = [];

        foreach ($series as $row) {
            $row['power_total'] === null && $noPower[] = $row['date'];
            $row['thermal'] === null && $noThermal[] = $row['date'];
            $row['temp'] === null && $noTemp[] = $row['date'];
        }

        $timestamps = ApiLog::query()
            ->where('source', '!=', 'backfill')
            ->whereBetween('timestamp', [$period->from->copy()->startOfDay(), $period->to->copy()->endOfDay()])
            ->distinct()
            ->orderBy('timestamp')
            ->pluck('timestamp')
            ->map(fn ($t) => Carbon::parse($t));

        $gaps = [];
        $previous = null;

        foreach ($timestamps as $ts) {
            if ($previous !== null && $previous->diffInHours($ts) >= $gapHours) {
                $gaps[] = [
                    'from' => $previous->format('d.m.Y H:i'),
                    'to' => $ts->format('d.m.Y H:i'),
                    'hours' => (int) round($previous->diffInHours($ts)),
                ];
            }

            $previous = $ts;
        }

        $corrected = 0;

        foreach ($series as $row) {
            $date = Carbon::parse($row['date']);
            $raw = $this->aggregation->rawDailyValue($date, 'power_total_kwh');
            $fixed = $this->aggregation->dailyPowerValue($date, 'power_total_kwh');

            if ($raw !== null && $fixed !== null && $fixed - $raw > 0.05) {
                $corrected++;
            }
        }

        $intervals = [];

        foreach (MeterReading::intervalPairs($period) as [$from, $to]) {
            $result = $this->correction->calculate($from, $to);
            $intervals[] = [
                'period' => $from->timestamp->format('d.m.Y') . ' – ' . $to->timestamp->format('d.m.Y'),
                'days' => round($from->timestamp->diffInSeconds($to->timestamp) / 86400, 1),
                'meter_kwh' => $result['meter_kwh'],
                'api_kwh' => $result['api_kwh'],
                'factor' => $result['correction_factor'],
                'missing_days' => count($result['missing_dates']),
                'estimated' => MeterReading::pairInvolvesEstimate($from, $to),
            ];
        }

        return [
            'days' => count($series),
            'no_power' => $noPower,
            'no_thermal' => $noThermal,
            'no_temp' => $noTemp,
            'gaps' => $gaps,
            'corrected_total_days' => $corrected,
            'intervals' => $intervals,
        ];
    }

    /**
     * Kosten- und Planbetrachtung fuer das Abrechnungsjahr.
     *
     * @return array<string, mixed>
     */
    public function costOutlook(ReportPeriod $period, ?float $planKwh, ?float $advanceMonthly, Carbon $billingStart): array
    {
        $now = Carbon::now()->startOfDay();
        $report = $this->reports->report($period);
        $meter = $report['meter'];
        $days = max(1, $report['period']['days']);

        $dailyKwh = $meter['consumption_kwh'] !== null && $meter['coverage_days'] > 0 ? $meter['consumption_kwh'] / $meter['coverage_days'] : null;
        $dailyCost = $meter['cost_eur'] !== null && $meter['coverage_days'] > 0 ? $meter['cost_eur'] / $meter['coverage_days'] : null;

        $billing = new ReportPeriod($billingStart->copy()->startOfDay(), $now, 'none', 'custom');
        $billingReport = $this->reports->report($billing)['meter'];
        $elapsed = (int) $billingStart->diffInDays($now) + 1;
        $remaining = max(0, 365 - $elapsed);
        $tariff = PriceHistory::validAt($now);

        $actualKwh = $billingReport['consumption_kwh'];
        $actualCost = $billingReport['cost_eur'];
        $monthsElapsed = max(0, (int) floor($billingStart->diffInMonths($now)));

        return [
            'period_days' => $days,
            'daily_kwh' => $dailyKwh,
            'daily_cost' => $dailyCost,
            'year_run_rate_kwh' => $dailyKwh !== null ? $dailyKwh * 365 : null,
            'year_run_rate_cost' => $dailyCost !== null ? $dailyCost * 365 : null,
            'coverage_label' => $meter['coverage_label'],
            'has_tariff' => $tariff !== null,
            'billing_start' => $billingStart->toDateString(),
            'elapsed_days' => $elapsed,
            'remaining_days' => $remaining,
            'billing_actual_kwh' => $actualKwh,
            'billing_actual_cost' => $actualCost,
            'billing_coverage_days' => $billingReport['coverage_days'],
            'plan_kwh' => $planKwh,
            'plan_to_date_kwh' => $planKwh !== null ? $planKwh * $elapsed / 365 : null,
            'advance_monthly' => $advanceMonthly,
            'advance_paid_to_date' => $advanceMonthly !== null ? $advanceMonthly * $monthsElapsed : null,
            'forecast_year_kwh' => ($actualKwh !== null && $dailyKwh !== null) ? $actualKwh + $dailyKwh * $remaining : null,
        ];
    }

    private function num(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }
}
