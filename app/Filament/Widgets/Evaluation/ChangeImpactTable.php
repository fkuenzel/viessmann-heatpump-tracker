<?php

namespace App\Filament\Widgets\Evaluation;

use App\Models\Annotation;
use App\Services\EvaluationService;
use App\Support\MetricTable;

class ChangeImpactTable extends EvaluationTableWidget
{
    protected function heading(): string
    {
        $annotation = Annotation::find($this->pageFilters['annotation'] ?? null);

        if (! $annotation) {
            return 'Wirkung: Nachher gegenüber Vorher';
        }

        $impact = app(EvaluationService::class)->changeImpact($annotation->timestamp, (int) ($this->pageFilters['days'] ?? 7));
        $note = $impact['after_days'] === 0 ? null : MetricTable::sumsNote($impact['report_after'], $impact['report_before']);

        return 'Wirkung: Nachher gegenüber Vorher' . ($note ? ' – Hinweis: ' . $note : '');
    }

    protected function columns(): array
    {
        return ['label' => 'Kennzahl', 'current' => 'Nachher', 'previous' => 'Vorher', 'delta' => 'Differenz', 'percent' => 'Änderung'];
    }

    protected function rows(): array
    {
        $annotation = Annotation::find($this->pageFilters['annotation'] ?? null);

        if (! $annotation) {
            return [];
        }

        $impact = app(EvaluationService::class)->changeImpact($annotation->timestamp, (int) ($this->pageFilters['days'] ?? 7));

        return $impact['after_days'] === 0 ? [] : MetricTable::rows($impact['report_after'], $impact['report_before']);
    }
}
