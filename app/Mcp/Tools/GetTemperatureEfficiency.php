<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

use App\Services\EvaluationService;
use App\Support\ReportPeriod;
use App\Support\ReportPeriodFilters;
use Illuminate\Validation\Rule;

#[Name('get_temperature_efficiency')]
#[Title('Effizienz nach Außentemperatur')]
#[Description('Tages-COP, Strom und Wärme je Tag und Mittelwerte nach Außentemperatur-Klasse (2 K) im Zeitraum. Grundlage, um Effizienz witterungsbereinigt zu beurteilen. Tage ohne Wärme- oder Temperaturwert fehlen.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetTemperatureEfficiency extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'preset' => $schema->string()->enum(array_keys(ReportPeriod::PRESETS))->description('Zeitraum-Voreinstellung. Standard: last_30. Bei custom gelten from und to.'),
            'from' => $schema->string()->format('date')->description('Nur bei preset=custom: erster Tag (JJJJ-MM-TT).'),
            'to' => $schema->string()->format('date')->description('Nur bei preset=custom: letzter Tag (JJJJ-MM-TT).'),
        ];
    }

    public function handle(Request $request, EvaluationService $service): Response|ResponseFactory
    {
        $data = $request->validate([
            'preset' => ['nullable', Rule::in(array_keys(ReportPeriod::PRESETS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $period = ReportPeriodFilters::resolve($data);

        if ($period->dayCount() > 400) {
            return Response::error('Der Zeitraum darf höchstens 400 Tage umfassen.');
        }

        $series = $service->dailySeries($period);

        return Response::structured([
            'label' => $period->label(),
            'classes' => $service->temperatureClasses($series),
            'days' => array_values(array_filter(array_map(fn ($r) => $r['temp'] === null || $r['cop'] === null ? null : [
                'date' => $r['date'], 'outdoor_temp_c' => round($r['temp'], 1), 'cop' => round($r['cop'], 2),
                'power_kwh' => $r['power_total'], 'thermal_kwh' => $r['thermal'],
            ], $series))),
        ]);
    }
}
