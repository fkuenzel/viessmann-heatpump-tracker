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

use App\Services\PeriodReportService;
use App\Support\ReportPeriod;
use Illuminate\Validation\Rule;

#[Name('get_period_report')]
#[Title('Zeitraum-Kennzahlen mit Vergleich')]
#[Description('Kennzahlen eines Zeitraums wie im Dashboard: Strom (App) gesamt/Heizen/Warmwasser, Wärme, COP (App und Zähler), Zählerverbrauch und Kosten aus vollständig enthaltenen Ablese-Intervallen mit Abdeckung, Außentemperatur, Heizgradtage und kWh je Heizgradtag (witterungsbereinigt), Kompressor, Heizstab. Optional mit Vergleichszeitraum samt Differenzen. Summen nur vergleichen, wenn beide Zeiträume gleich lang und ausreichend belegt sind (Abdeckung beachten).')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetPeriodReport extends Tool
{
    use InteractsWithToolInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'preset' => $schema->string()->enum(array_keys(ReportPeriod::PRESETS))->description('Zeitraum-Voreinstellung. Standard: last_30. Bei custom gelten from und to.'),
            'from' => $schema->string()->format('date')->description('Nur bei preset=custom: erster Tag (JJJJ-MM-TT).'),
            'to' => $schema->string()->format('date')->description('Nur bei preset=custom: letzter Tag (JJJJ-MM-TT).'),
            'compare' => $schema->string()->enum(array_keys(ReportPeriod::COMPARE_MODES))->description('Vergleich: previous (vorheriger Zeitraum, Standard), previous_year, none.'),
        ];
    }

    public function handle(Request $request, PeriodReportService $service): Response|ResponseFactory
    {
        $data = $request->validate([
            'preset' => ['nullable', Rule::in(array_keys(ReportPeriod::PRESETS))],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'compare' => ['nullable', Rule::in(array_keys(ReportPeriod::COMPARE_MODES))],
        ]);

        $period = \App\Support\ReportPeriodFilters::resolve($data);

        if ($period->dayCount() > 800) {
            return Response::error('Der Zeitraum darf höchstens 800 Tage umfassen.');
        }

        return Response::structured([
            'label' => $period->label(),
            ...$service->reportWithComparison($period),
        ]);
    }
}
