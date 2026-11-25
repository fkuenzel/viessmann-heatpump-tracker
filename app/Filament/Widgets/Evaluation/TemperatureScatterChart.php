<?php

namespace App\Filament\Widgets\Evaluation;

use App\Services\EvaluationService;

class TemperatureScatterChart extends EvaluationChartWidget
{
    protected ?string $heading = 'Tages-COP über Außentemperatur';

    protected ?string $maxHeight = '380px';

    protected function getType(): string
    {
        return 'scatter';
    }

    protected function getData(): array
    {
        $points = [];

        foreach (app(EvaluationService::class)->dailySeries($this->period()) as $row) {
            if ($row['temp'] !== null && $row['cop'] !== null) {
                $points[] = ['x' => round($row['temp'], 1), 'y' => round($row['cop'], 2)];
            }
        }

        return ['datasets' => [['label' => 'Tag', 'data' => $points, 'backgroundColor' => '#2563eb']]];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['title' => ['display' => true, 'text' => 'Außentemperatur Tagesmittel (°C)']],
                'y' => ['title' => ['display' => true, 'text' => 'COP (App)']],
            ],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
