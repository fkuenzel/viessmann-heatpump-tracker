<?php

namespace App\Filament\Widgets\Evaluation;

use App\Models\Annotation;
use App\Services\EvaluationService;

class ChangeImpactSummary extends EvaluationTableWidget
{
    protected function heading(): string
    {
        return 'Betrachtete Fenster';
    }

    protected function columns(): array
    {
        return ['window' => 'Fenster', 'range' => 'Zeitraum', 'days' => 'Tage', 'note' => 'Hinweis'];
    }

    protected function emptyText(): string
    {
        return 'Bitte eine Änderung (Notiz) auswählen. Es sind noch keine Notizen vorhanden.';
    }

    protected function rows(): array
    {
        $annotation = Annotation::find($this->pageFilters['annotation'] ?? null);

        if (! $annotation) {
            return [];
        }

        $days = (int) ($this->pageFilters['days'] ?? 7);
        $impact = app(EvaluationService::class)->changeImpact($annotation->timestamp, $days);
        $afterNote = $impact['after_days'] === 0
            ? 'Noch keine abgeschlossenen Tage nach der Änderung.'
            : ($impact['after_days'] < $days ? 'Nur ' . $impact['after_days'] . ' von ' . $days . ' Tagen vorhanden – Aussage noch unsicher.' : '');

        return [
            ['window' => 'Änderung', 'range' => $annotation->timestamp->format('d.m.Y H:i'), 'days' => '', 'note' => $annotation->note],
            ['window' => 'Vorher', 'range' => $impact['before']->label(), 'days' => (string) $impact['report_before']['period']['days'], 'note' => ''],
            ['window' => 'Nachher', 'range' => $impact['after_days'] ? $impact['after']->label() : '–', 'days' => (string) $impact['after_days'], 'note' => $afterNote],
        ];
    }
}
