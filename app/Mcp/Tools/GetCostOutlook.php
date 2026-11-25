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

use App\Models\Setting;
use App\Services\EvaluationService;
use App\Support\ReportPeriod;
use App\Support\ReportPeriodFilters;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

#[Name('get_cost_outlook')]
#[Title('Kosten und Jahresprognose')]
#[Description('Durchschnittsverbrauch und -kosten im Zeitraum, lineare Hochrechnung auf 365 Tage, Verbrauch und Kosten des Abrechnungsjahres bis heute (aus Zählerständen), Plan (Einstellung plan_kwh) und Abschläge (advance_monthly_eur). Die Prognose ist unsicher, wenn die Zählerstände nur einen Teil des Abrechnungsjahres abdecken (billing_coverage_days gegen elapsed_days). Winterwerte überschätzen die Jahreshochrechnung.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetCostOutlook extends Tool
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
        $plan = Setting::get('plan_kwh');
        $advance = Setting::get('advance_monthly_eur');
        $start = Setting::get('billing_year_start');

        return Response::structured($service->costOutlook(
            $period,
            is_numeric($plan) ? (float) $plan : null,
            is_numeric($advance) ? (float) $advance : null,
            $start ? Carbon::parse($start) : Carbon::now()->startOfYear(),
        ));
    }
}
