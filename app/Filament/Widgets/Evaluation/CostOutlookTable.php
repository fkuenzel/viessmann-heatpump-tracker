<?php

namespace App\Filament\Widgets\Evaluation;

use App\Models\Setting;
use App\Services\EvaluationService;
use Illuminate\Support\Carbon;

class CostOutlookTable extends EvaluationTableWidget
{
    protected function heading(): string
    {
        return 'Kosten und Jahresprognose';
    }

    protected function columns(): array
    {
        return ['label' => 'Kennzahl', 'value' => 'Wert', 'note' => 'Hinweis'];
    }

    protected function rows(): array
    {
        $plan = Setting::get('plan_kwh');
        $advance = Setting::get('advance_monthly_eur');
        $start = Setting::get('billing_year_start');
        $billingStart = $start ? Carbon::parse($start) : Carbon::now()->startOfYear();

        $o = app(EvaluationService::class)->costOutlook(
            $this->period(),
            is_numeric($plan) ? (float) $plan : null,
            is_numeric($advance) ? (float) $advance : null,
            $billingStart,
        );

        $lowCoverage = $o['elapsed_days'] > 0 && ($o['billing_coverage_days'] / $o['elapsed_days']) < 0.8;
        $f = fn (?float $v, int $d = 1, string $u = '') => $v === null ? '–' : number_format($v, $d, ',', '.') . ($u !== '' ? ' ' . $u : '');
        $pct = fn (?float $a, ?float $b) => ($a !== null && $b !== null && $b > 0) ? ($a >= $b ? '+' : '') . number_format(($a / $b - 1) * 100, 1, ',', '.') . ' % zum Plan' : '';

        return [
            ['label' => 'Ø Verbrauch je Tag (Zeitraum)', 'value' => $f($o['daily_kwh'], 2, 'kWh'), 'note' => $o['coverage_label'] ?? ''],
            ['label' => 'Ø Kosten je Tag (Zeitraum)', 'value' => $f($o['daily_cost'], 2, '€'), 'note' => $o['has_tariff'] ? '' : 'Kein Tarif hinterlegt'],
            ['label' => 'Hochrechnung 365 Tage (Zeitraum)', 'value' => $f($o['year_run_rate_kwh'], 0, 'kWh') . ' / ' . $f($o['year_run_rate_cost'], 0, '€'), 'note' => 'Linear fortgeschrieben, Winterwerte überschätzen das Jahr'],
            ['label' => 'Abrechnungsjahr ab', 'value' => Carbon::parse($o['billing_start'])->format('d.m.Y'), 'note' => 'Einstellbar unter Einstellungen'],
            ['label' => 'Bisher verbraucht (Zähler)', 'value' => $f($o['billing_actual_kwh'], 0, 'kWh'), 'note' => 'Durch Zählerstände belegt: ' . $f((float) $o['billing_coverage_days'], 0) . ' von ' . $o['elapsed_days'] . ' Tagen'],
            ['label' => 'Bisherige Kosten (Zähler)', 'value' => $f($o['billing_actual_cost'], 2, '€'), 'note' => ''],
            ['label' => 'Plan bis heute', 'value' => $f($o['plan_to_date_kwh'], 0, 'kWh'), 'note' => $o['plan_kwh'] === null ? 'Jahresplan in Einstellungen eintragen' : ''],
            ['label' => 'Abweichung zum Plan', 'value' => $pct($o['billing_actual_kwh'], $o['plan_to_date_kwh']) ?: '–', 'note' => ''],
            ['label' => 'Prognose Jahresverbrauch', 'value' => $f($o['forecast_year_kwh'], 0, 'kWh'), 'note' => $lowCoverage ? 'Unsicher: Zählerstände decken nur einen Teil des Abrechnungsjahres ab. Bisher plus Ø Tagesverbrauch × Resttage' : 'Bisher plus Ø Tagesverbrauch × Resttage'],
            ['label' => 'Abschläge bis heute', 'value' => $f($o['advance_paid_to_date'], 2, '€'), 'note' => $o['advance_monthly'] === null ? 'Monatlichen Abschlag in Einstellungen eintragen' : ''],
        ];
    }
}
