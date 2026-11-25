<?php

namespace App\Filament\Widgets\Evaluation;

use App\Services\PeriodReportService;
use App\Support\MetricTable;

class PeriodComparisonTable extends EvaluationTableWidget
{
    protected function heading(): string
    {
        $p = $this->period();
        $c = $p->comparison();

        $service = app(PeriodReportService::class);
        $note = $c ? MetricTable::sumsNote($service->report($p), $service->report($c)) : null;

        return 'Periodenvergleich: ' . $p->label() . ($c ? ' gegenüber ' . $c->label() : '') . ($note ? ' – Hinweis: ' . $note : '');
    }

    protected function columns(): array
    {
        return ['label' => 'Kennzahl', 'current' => 'Aktuell', 'previous' => 'Vergleich', 'delta' => 'Differenz', 'percent' => 'Änderung'];
    }

    protected function rows(): array
    {
        $service = app(PeriodReportService::class);
        $period = $this->period();
        $current = $service->report($period);
        $comparison = $period->comparison();

        return MetricTable::rows($current, $comparison ? $service->report($comparison) : []);
    }
}
