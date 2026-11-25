<?php

namespace App\Filament\Widgets\Evaluation;

use App\Services\EvaluationService;

class QualityIntervalsTable extends EvaluationTableWidget
{
    protected function heading(): string
    {
        return 'Ablese-Intervalle im Zeitraum';
    }

    protected function emptyText(): string
    {
        return 'Keine vollständigen Ablese-Intervalle im Zeitraum.';
    }

    protected function columns(): array
    {
        return ['period' => 'Intervall', 'days' => 'Tage', 'meter_kwh' => 'Zähler (kWh)', 'api_kwh' => 'App (kWh)', 'factor' => 'Faktor', 'missing_days' => 'Fehlende Tage', 'estimated' => 'Hinweis'];
    }

    protected function rows(): array
    {
        $f = fn ($v, $d) => $v === null ? '–' : number_format((float) $v, $d, ',', '.');

        return array_map(fn ($i) => [
            'period' => $i['period'],
            'days' => $f($i['days'], 1),
            'meter_kwh' => $f($i['meter_kwh'], 1),
            'api_kwh' => $f($i['api_kwh'], 1),
            'factor' => $f($i['factor'], 3),
            'missing_days' => (string) $i['missing_days'],
            'estimated' => $i['estimated'] ? 'Schätzwert beteiligt' : '',
        ], app(EvaluationService::class)->dataQuality($this->period(), 6)['intervals']);
    }
}
