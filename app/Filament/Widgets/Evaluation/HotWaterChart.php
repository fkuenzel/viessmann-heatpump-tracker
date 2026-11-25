<?php

namespace App\Filament\Widgets\Evaluation;

use App\Services\EvaluationService;

class HotWaterChart extends EvaluationChartWidget
{
    protected ?string $heading = 'Warmwasser: Strom je Tag und COP';

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
                ['type' => 'bar', 'label' => 'Strom Warmwasser (kWh)', 'data' => array_column($series, 'power_dhw'), 'backgroundColor' => '#f59e0b', 'yAxisID' => 'y'],
                ['type' => 'line', 'label' => 'COP Warmwasser', 'data' => array_column($series, 'cop_dhw'), 'borderColor' => '#2563eb', 'backgroundColor' => '#2563eb', 'yAxisID' => 'y1', 'spanGaps' => false],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => [
            'y' => ['position' => 'left', 'title' => ['display' => true, 'text' => 'kWh']],
            'y1' => ['position' => 'right', 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'COP']],
        ]];
    }
}
