<?php

namespace App\Filament\Widgets\Evaluation;

use App\Services\EvaluationService;

class CompressorChart extends EvaluationChartWidget
{
    protected ?string $heading = 'Verdichterstarts und Laufzeit je Tag';

    protected ?string $maxHeight = '340px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $series = app(EvaluationService::class)->dailySeries($this->period());

        return [
            'labels' => array_map(fn ($r) => date('d.m.', strtotime($r['date'])), $series),
            'datasets' => [
                ['type' => 'bar', 'label' => 'Starts', 'data' => array_column($series, 'starts'), 'backgroundColor' => '#64748b', 'yAxisID' => 'y'],
                ['type' => 'line', 'label' => 'Laufzeit (h)', 'data' => array_column($series, 'hours'), 'borderColor' => '#2563eb', 'backgroundColor' => '#2563eb', 'yAxisID' => 'y1'],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => [
            'y' => ['position' => 'left', 'title' => ['display' => true, 'text' => 'Starts']],
            'y1' => ['position' => 'right', 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Stunden']],
        ]];
    }
}
