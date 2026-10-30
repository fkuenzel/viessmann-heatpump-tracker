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
     * Warnt, wenn seit dem zuletzt geschriebenen api_log-Eintrag (vor diesem
     * Sync-Lauf) mehr Zeit vergangen ist als der konfigurierte Schwellenwert
     * - typischer Fall: Herd/Rechner war laengere Zeit aus (kein
     * Dauerbetrieb, Abschnitt 0).
     */
    private function checkSyncOutage(string $recipient, Carbon $now, ?Carbon $latestLogTimestampBeforeSync): void
    {
        if ($latestLogTimestampBeforeSync === null) {
            return;
        }

        $thresholdHours = (int) Setting::get('sync_outage_threshold_hours', 24);
        $gapHours = $latestLogTimestampBeforeSync->diffInHours($now);

        if ($gapHours < $thresholdHours) {
            return;
        }

        if (Setting::get('last_outage_warning_date') === $now->toDateString()) {
            return;
        }

        Mail::to($recipient)->send(new SyncOutageWarningMail($latestLogTimestampBeforeSync, $now, $gapHours));

        Setting::set('last_outage_warning_date', $now->toDateString());
    }

    /**
     * Vergleicht den Gesamt-Stromverbrauch des zuletzt vollstaendig
     * abgeschlossenen Tages (gestern) mit dem gleitenden Durchschnitt der
     * vorangegangenen `anomaly_lookback_days` Tage. Warnt bei Ueberschreitung
     * des konfigurierten Prozentsatzes.
     */
    private function checkAnomaly(string $recipient, Carbon $now): void
    {
        if (Setting::get('last_anomaly_warning_date') === $now->toDateString()) {
            return;
        }

        $yesterday = $now->copy()->subDay()->startOfDay();
        $value = $this->aggregation->dailyPowerValue($yesterday, 'power_total_kwh');

        if ($value === null) {
            return;
        }

        $lookbackDays = (int) Setting::get('anomaly_lookback_days', 14);
        $thresholdPercent = (float) Setting::get('anomaly_threshold_percent', 30);

        $comparisonValues = [];
        for ($i = 1; $i <= $lookbackDays; $i++) {
            $dayValue = $this->aggregation->dailyPowerValue($yesterday->copy()->subDays($i), 'power_total_kwh');

            if ($dayValue !== null) {
                $comparisonValues[] = $dayValue;
            }
        }

        // Ohne ausreichend Vergleichsbasis (z. B. ganz am Anfang des Betriebs)
        // laesst sich keine belastbare Abweichung berechnen.
        if (count($comparisonValues) < 3) {
            return;
        }

        $average = array_sum($comparisonValues) / count($comparisonValues);

        if ($average <= 0) {
            return;
        }

        $deviationPercent = (($value - $average) / $average) * 100;

        if ($deviationPercent < $thresholdPercent) {
            return;
        }

        Mail::to($recipient)->send(new AnomalyWarningMail($yesterday, $value, $average, $deviationPercent));

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
