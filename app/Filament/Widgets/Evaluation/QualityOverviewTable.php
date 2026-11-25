<?php

namespace App\Filament\Widgets\Evaluation;

use App\Services\EvaluationService;

class QualityOverviewTable extends EvaluationTableWidget
{
    protected function heading(): string
    {
        return 'Datenlage im Zeitraum';
    }

    protected function columns(): array
    {
        return ['label' => 'Prüfung', 'value' => 'Anzahl', 'detail' => 'Betroffene Tage'];
    }

    protected function rows(): array
    {
        $q = app(EvaluationService::class)->dataQuality($this->period(), 6);
        $list = fn (array $d) => $d === [] ? '–' : implode(', ', array_map(fn ($x) => date('d.m.', strtotime($x)), array_slice($d, 0, 12))) . (count($d) > 12 ? ' …' : '');

        return [
            ['label' => 'Tage im Zeitraum (abgeschlossen)', 'value' => (string) $q['days'], 'detail' => ''],
            ['label' => 'Ohne Stromwert', 'value' => (string) count($q['no_power']), 'detail' => $list($q['no_power'])],
            ['label' => 'Ohne Wärmewert (Momentaufnahme fehlt)', 'value' => (string) count($q['no_thermal']), 'detail' => $list($q['no_thermal'])],
            ['label' => 'Ohne Außentemperatur', 'value' => (string) count($q['no_temp']), 'detail' => $list($q['no_temp'])],
            ['label' => 'Tage mit Gesamtstrom-Korrektur (Summe aus Heizen und Warmwasser)', 'value' => (string) $q['corrected_total_days'], 'detail' => ''],
        ];
    }
}
