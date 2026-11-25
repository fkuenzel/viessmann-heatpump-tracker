<?php

namespace App\Filament\Widgets\Evaluation;

use App\Services\EvaluationService;

class CompressorTable extends EvaluationTableWidget
{
    protected function heading(): string
    {
        return 'Takten im Zeitraum';
    }

    protected function columns(): array
    {
        return ['label' => 'Kennzahl', 'value' => 'Wert'];
    }

    protected function rows(): array
    {
        $series = app(EvaluationService::class)->dailySeries($this->period());
        $days = array_values(array_filter($series, fn ($r) => $r['starts'] !== null && $r['hours'] !== null));

        if ($days === []) {
            return [];
        }

        $n = count($days);
        $starts = array_sum(array_column($days, 'starts'));
        $hours = array_sum(array_column($days, 'hours'));
        $f = fn (float $v, int $d = 1) => number_format($v, $d, ',', '.');
        $worst = collect($days)->sortByDesc('starts')->first();
        $rodDays = array_filter($series, fn ($r) => ($r['rod_kwh'] ?? 0) > 0);

        return [
            ['label' => 'Tage mit Zählerdifferenz', 'value' => (string) $n],
            ['label' => 'Verdichterstarts gesamt', 'value' => $f($starts, 0)],
            ['label' => 'Starts je Tag', 'value' => $f($starts / $n)],
            ['label' => 'Laufzeit gesamt', 'value' => $f($hours) . ' h'],
            ['label' => 'Ø Laufzeit je Start', 'value' => $starts > 0 ? $f($hours * 60 / $starts) . ' min' : '–'],
            ['label' => 'Tag mit den meisten Starts', 'value' => date('d.m.Y', strtotime($worst['date'])) . ' (' . $f($worst['starts'], 0) . ')'],
            ['label' => 'Tage mit Heizstab-Einsatz', 'value' => (string) count($rodDays)],
        ];
    }
}
