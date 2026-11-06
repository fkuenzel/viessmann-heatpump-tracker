<?php

namespace App\Services;

use App\Mail\AnomalyWarningMail;
use App\Mail\PeriodicSummaryMail;
use App\Mail\SyncOutageWarningMail;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Mail-Reporting (Fahrplan Phase 6, Fabian 29.10.2026): periodische
 * Zusammenfassung (woechentlich/monatlich, konfigurierbar) + Warnungen bei
 * auffaelligem Tageswert und bei Sync-Ausfall. Wird am Ende jedes Sync-Laufs
 * aufgerufen (ViessmannSyncService::sync()), nicht ueber einen separaten
 * Scheduler-Job - die App laeuft nicht dauerhaft (Abschnitt 0), ein Job, der
 * nur zu einer festen Uhrzeit feuert, wuerde regelmaessig verpasst.
 *
 * Ohne hinterlegte Empfaenger-Adresse (`mail_recipient` in den Einstellungen)
 * passiert nichts - Mail-Reporting ist dadurch komplett optional.
 *
 * Alle drei Pruefungen debouncen auf "maximal einmal pro Kalendertag" ueber
 * je einen eigenen `last_*`-Settings-Schluessel, damit bei einem alle
 * 15 Minuten laufenden Scheduler keine Mail-Flut entsteht, solange eine
 * Bedingung ueber mehrere Sync-Laeufe hinweg anhaelt.
 */
class MailReportingService
{
    public function __construct(
        private readonly ApiLogAggregationService $aggregation,
        private readonly CopCalculationService $cop,
    ) {
    }

    public function checkAndNotify(Carbon $now, ?Carbon $latestLogTimestampBeforeSync): void
    {
        $recipient = Setting::get('mail_recipient');

        if (! $recipient) {
            return;
        }

        $this->checkSyncOutage($recipient, $now, $latestLogTimestampBeforeSync);
        $this->checkAnomaly($recipient, $now);
        $this->checkPeriodicSummary($recipient, $now);
    }

    /**
     * Bewertet, ob seit dem zuletzt geschriebenen api_log-Eintrag mehr Zeit
     * vergangen ist als der konfigurierte Schwellenwert - typischer Fall:
     * Herd/Rechner war längere Zeit aus (kein Dauerbetrieb, Abschnitt 0).
     * Reine Auswertung ohne Seiteneffekte (kein Mailversand, kein Debounce),
     * damit sie auch der MCP-Server (App\Mcp\Tools\CheckAnomalies) nutzen
     * kann.
     *
     * @return array{status: string, gap_hours: ?int, threshold_hours: int, last_entry_at: ?Carbon}
     *         status: 'no_data' (noch kein api_log-Eintrag), 'ok' oder 'outage'
     */
    public function evaluateSyncOutage(Carbon $now, ?Carbon $latestLogTimestamp): array
    {
        $thresholdHours = (int) Setting::get('sync_outage_threshold_hours', 24);

        if ($latestLogTimestamp === null) {
            return [
                'status' => 'no_data',
                'gap_hours' => null,
                'threshold_hours' => $thresholdHours,
                'last_entry_at' => null,
            ];
        }

        // Carbon 3 liefert diffInHours() als float; SyncOutageWarningMail erwartet int.
        $gapHours = (int) $latestLogTimestamp->diffInHours($now);

        return [
            'status' => $gapHours >= $thresholdHours ? 'outage' : 'ok',
            'gap_hours' => $gapHours,
            'threshold_hours' => $thresholdHours,
            'last_entry_at' => $latestLogTimestamp,
        ];
    }

    private function checkSyncOutage(string $recipient, Carbon $now, ?Carbon $latestLogTimestampBeforeSync): void
    {
        $result = $this->evaluateSyncOutage($now, $latestLogTimestampBeforeSync);

        if ($result['status'] !== 'outage') {
            return;
        }

        if (Setting::get('last_outage_warning_date') === $now->toDateString()) {
            return;
        }

        Mail::to($recipient)->send(new SyncOutageWarningMail($latestLogTimestampBeforeSync, $now, $result['gap_hours']));

        Setting::set('last_outage_warning_date', $now->toDateString());
    }

    /**
     * Vergleicht den Gesamt-Stromverbrauch des zuletzt vollständig
     * abgeschlossenen Tages (gestern) mit dem gleitenden Durchschnitt der
     * vorangegangenen `anomaly_lookback_days` Tage und bewertet, ob die
     * Abweichung den konfigurierten Prozentsatz erreicht. Reine Auswertung
     * ohne Seiteneffekte (kein Mailversand, kein Debounce), damit sie auch
     * der MCP-Server (App\Mcp\Tools\CheckAnomalies) nutzen kann.
     *
     * @return array{
     *     status: string,
     *     date: Carbon,
     *     value_kwh: ?float,
     *     average_kwh: ?float,
     *     deviation_percent: ?float,
     *     threshold_percent: float,
     *     lookback_days: int,
     *     comparison_days: int,
     * }
     *         status: 'no_value' (kein Tageswert für gestern), 'insufficient_data'
     *         (weniger als 3 Vergleichstage oder Durchschnitt 0), 'ok' oder 'anomaly'
     */
    public function evaluateAnomaly(Carbon $now): array
    {
        $yesterday = $now->copy()->subDay()->startOfDay();
        $lookbackDays = (int) Setting::get('anomaly_lookback_days', 14);
        $thresholdPercent = (float) Setting::get('anomaly_threshold_percent', 30);

        $result = [
            'status' => 'no_value',
            'date' => $yesterday,
            'value_kwh' => null,
            'average_kwh' => null,
            'deviation_percent' => null,
            'threshold_percent' => $thresholdPercent,
            'lookback_days' => $lookbackDays,
            'comparison_days' => 0,
        ];

        $value = $this->aggregation->dailyPowerValue($yesterday, 'power_total_kwh');

        if ($value === null) {
            return $result;
        }

        $result['value_kwh'] = $value;

        $comparisonValues = [];
        for ($i = 1; $i <= $lookbackDays; $i++) {
            $dayValue = $this->aggregation->dailyPowerValue($yesterday->copy()->subDays($i), 'power_total_kwh');

            if ($dayValue !== null) {
                $comparisonValues[] = $dayValue;
            }
        }

        $result['comparison_days'] = count($comparisonValues);

        // Ohne ausreichend Vergleichsbasis (z. B. ganz am Anfang des Betriebs)
        // lässt sich keine belastbare Abweichung berechnen.
        if (count($comparisonValues) < 3) {
            $result['status'] = 'insufficient_data';

            return $result;
        }

        $average = array_sum($comparisonValues) / count($comparisonValues);
        $result['average_kwh'] = $average;

        if ($average <= 0) {
            $result['status'] = 'insufficient_data';

            return $result;
        }

        $deviationPercent = (($value - $average) / $average) * 100;
        $result['deviation_percent'] = $deviationPercent;
        $result['status'] = $deviationPercent >= $thresholdPercent ? 'anomaly' : 'ok';

        return $result;
    }

    private function checkAnomaly(string $recipient, Carbon $now): void
    {
        if (Setting::get('last_anomaly_warning_date') === $now->toDateString()) {
            return;
        }

        $result = $this->evaluateAnomaly($now);

        if ($result['status'] !== 'anomaly') {
            return;
        }

        Mail::to($recipient)->send(new AnomalyWarningMail(
            $result['date'],
            $result['value_kwh'],
            $result['average_kwh'],
            $result['deviation_percent'],
        ));

        Setting::set('last_anomaly_warning_date', $now->toDateString());
    }

    /**
     * Verschickt eine periodische Zusammenfassung (Frequenz aus Settings),
     * sobald der jeweilige Zeitraum vollstaendig abgeschlossen ist und noch
     * keine Zusammenfassung dafuer verschickt wurde.
     */
    private function checkPeriodicSummary(string $recipient, Carbon $now): void
    {
        $frequency = Setting::get('report_frequency', 'weekly');

        [$periodStart, $periodEnd, $periodLabel] = $frequency === 'monthly'
            ? $this->previousMonthBounds($now)
            : $this->previousWeekBounds($now);

        if ($periodEnd->greaterThanOrEqualTo($now->copy()->startOfDay())) {
            return;
        }

        if (Setting::get('last_summary_period_end') === $periodEnd->toDateString()) {
            return;
        }

        $summary = $this->buildSummary($periodStart, $periodEnd);

        Mail::to($recipient)->send(new PeriodicSummaryMail($periodLabel, $periodStart, $periodEnd, $summary));

        Setting::set('last_summary_period_end', $periodEnd->toDateString());
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function previousWeekBounds(Carbon $now): array
    {
        $end = $now->copy()->startOfWeek()->subDay();
        $start = $end->copy()->subDays(6);

        return [$start, $end, 'Woche ' . $start->format('d.m.') . ' - ' . $end->format('d.m.Y')];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function previousMonthBounds(Carbon $now): array
    {
        $end = $now->copy()->startOfMonth()->subDay();
        $start = $end->copy()->startOfMonth();

        return [$start, $end, $start->translatedFormat('F Y')];
    }

    /**
     * @return array{power_total: ?float, power_heating: ?float, power_dhw: ?float, thermal_total: ?float, avg_cop: ?float, avg_outdoor_temp: ?float}
     */
    private function buildSummary(Carbon $start, Carbon $end): array
    {
        $powerTotal = $this->sumDaily('power_total_kwh', $start, $end);
        $powerHeating = $this->sumDaily('power_heating_kwh', $start, $end);
        $powerDhw = $this->sumDaily('power_dhw_kwh', $start, $end);

        $thermalHeating = $this->sumDaily('thermal_heating_kwh', $start, $end);
        $thermalDhw = $this->sumDaily('thermal_dhw_kwh', $start, $end);
        $thermalTotal = ($thermalHeating === null && $thermalDhw === null)
            ? null
            : ($thermalHeating ?? 0) + ($thermalDhw ?? 0);

        $copResult = $this->cop->forRange($start, $end);

        $outdoorTemps = [];
        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            $temp = $this->aggregation->lastSnapshotValue($cursor, 'outdoor_temp');

            if ($temp !== null) {
                $outdoorTemps[] = $temp;
            }

            $cursor->addDay();
        }

        return [
            'power_total' => $powerTotal,
            'power_heating' => $powerHeating,
            'power_dhw' => $powerDhw,
            'thermal_total' => $thermalTotal,
            'avg_cop' => $copResult['cop'],
            'avg_outdoor_temp' => $outdoorTemps === [] ? null : round(array_sum($outdoorTemps) / count($outdoorTemps), 1),
        ];
    }

    private function sumDaily(string $column, Carbon $start, Carbon $end): ?float
    {
        $sum = 0.0;
        $hasValue = false;

        $cursor = $start->copy();
        while ($cursor->lessThanOrEqualTo($end)) {
            $value = $this->aggregation->dailyPowerValue($cursor, $column);

            if ($value !== null) {
                $sum += $value;
                $hasValue = true;
            }

            $cursor->addDay();
        }

        return $hasValue ? round($sum, 2) : null;
    }
}
