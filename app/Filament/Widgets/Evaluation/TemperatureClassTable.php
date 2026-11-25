<?php

namespace App\Filament\Widgets\Evaluation;

use App\Services\EvaluationService;

class TemperatureClassTable extends EvaluationTableWidget
{
    protected function heading(): string
    {
        return 'COP und Verbrauch nach Temperaturklasse (2 K)';
    }

    protected function columns(): array
    {
        return ['class' => 'Außentemperatur', 'days' => 'Tage', 'avg_cop' => 'Ø COP', 'avg_power' => 'Ø Strom/Tag (kWh)', 'avg_thermal' => 'Ø Wärme/Tag (kWh)', 'rod_kwh' => 'Heizstab (kWh)'];
    }

    protected function rows(): array
    {
        $service = app(EvaluationService::class);

        return array_map(function (array $r): array {
            return [
                'class' => $r['class'],
                'days' => (string) $r['days'],
                'avg_cop' => number_format($r['avg_cop'], 2, ',', '.'),
                'avg_power' => number_format($r['avg_power'], 1, ',', '.'),
                'avg_thermal' => number_format($r['avg_thermal'], 1, ',', '.'),
                'rod_kwh' => number_format($r['rod_kwh'], 2, ',', '.'),
            ];
        }, $service->temperatureClasses($service->dailySeries($this->period())));
    }
}
