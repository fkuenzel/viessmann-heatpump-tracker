<?php

namespace App\Filament\Widgets\Evaluation;

use App\Services\EvaluationService;

class HotWaterTable extends EvaluationTableWidget
{
    protected function heading(): string
    {
        return 'Warmwasser im Zeitraum';
    }

    protected function columns(): array
    {
        return ['label' => 'Kennzahl', 'value' => 'Wert'];
    }

    protected function rows(): array
    {
        $series = app(EvaluationService::class)->dailySeries($this->period());
        $with = array_values(array_filter($series, fn ($r) => $r['power_dhw'] !== null));

        if ($with === []) {
            return [];
        }

        $n = count($with);
        $power = array_sum(array_column($with, 'power_dhw'));
        $thermalRows = array_filter($with, fn ($r) => $r['thermal_dhw'] !== null);
        $thermal = array_sum(array_column($thermalRows, 'thermal_dhw'));
        $powerThermal = array_sum(array_column($thermalRows, 'power_dhw'));
        $totalPower = array_sum(array_map(fn ($r) => $r['power_total'] ?? 0.0, $with));
        $f = fn (float $v, int $d = 1) => number_format($v, $d, ',', '.');

        return [
            ['label' => 'Tage mit Daten', 'value' => (string) $n],
            ['label' => 'Strom Warmwasser gesamt', 'value' => $f($power) . ' kWh'],
            ['label' => 'Strom Warmwasser je Tag', 'value' => $f($power / $n, 2) . ' kWh'],
            ['label' => 'Anteil am Gesamtstrom', 'value' => $totalPower > 0 ? $f($power / $totalPower * 100) . ' %' : '–'],
            ['label' => 'Wärme Warmwasser gesamt', 'value' => $thermalRows ? $f($thermal) . ' kWh' : '–'],
            ['label' => 'COP Warmwasser', 'value' => $powerThermal > 0 ? $f($thermal / $powerThermal, 2) : '–'],
        ];
    }
}
