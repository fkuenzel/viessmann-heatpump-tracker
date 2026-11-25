<?php

namespace App\Filament\Widgets\Evaluation;

use App\Services\EvaluationService;

class QualityGapsTable extends EvaluationTableWidget
{
    protected function heading(): string
    {
        return 'Sync-Lücken (6 Stunden und mehr)';
    }

    protected function emptyText(): string
    {
        return 'Keine Sync-Lücken im Zeitraum.';
    }

    protected function columns(): array
    {
        return ['from' => 'Letzter Sync davor', 'to' => 'Nächster Sync', 'hours' => 'Lücke (h)'];
    }

    protected function rows(): array
    {
        return array_map(fn ($g) => [...$g, 'hours' => (string) $g['hours']], app(EvaluationService::class)->dataQuality($this->period(), 6)['gaps']);
    }
}
