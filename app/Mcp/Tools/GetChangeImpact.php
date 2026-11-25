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
use Illuminate\Support\Carbon;

#[Name('get_change_impact')]
#[Title('Wirkung einer Änderung')]
#[Description('Vergleicht N Tage vor und N Tage nach einem Zeitpunkt (z. B. einer Heizkurven-Änderung aus get_annotations) mit denselben Kennzahlen wie get_period_report. Das Nachher-Fenster endet spätestens gestern; after_days zeigt, wie viele Tage tatsächlich vorliegen. Bei weniger Tagen oder anderem Wetter ist die Aussage unsicher: weather.heating_kwh_per_degree_day und der COP sind die wetterbereinigten Größen.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetChangeImpact extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'at' => $schema->string()->description('Zeitpunkt der Änderung (JJJJ-MM-TT oder JJJJ-MM-TT HH:MM).')->required(),
            'days' => $schema->integer()->description('Tage vor und nach der Änderung (1 bis 30). Standard: 7.'),
        ];
    }

    public function handle(Request $request, EvaluationService $service): Response|ResponseFactory
    {
        $data = $request->validate([
            'at' => ['required', 'date', 'before_or_equal:now'],
            'days' => ['nullable', 'integer', 'between:1,30'],
        ]);

        $impact = $service->changeImpact(Carbon::parse($data['at']), (int) ($data['days'] ?? 7));

        return Response::structured([
            'before' => ['from' => $impact['before']->from->toDateString(), 'to' => $impact['before']->to->toDateString(), 'report' => $impact['report_before']],
            'after' => $impact['after_days'] === 0 ? null : ['from' => $impact['after']->from->toDateString(), 'to' => $impact['after']->to->toDateString(), 'days' => $impact['after_days'], 'report' => $impact['report_after']],
            'deltas' => $impact['deltas'],
            'note' => $impact['after_days'] === 0 ? 'Noch keine abgeschlossenen Tage nach der Änderung.' : 'Summen-Deltas sind nur bei gleich langen Fenstern aussagekräftig; sonst Tageswerte und Verhältniszahlen nutzen.',
        ]);
    }
}
