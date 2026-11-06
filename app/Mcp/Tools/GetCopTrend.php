<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use App\Services\CopCalculationService;
use App\Services\MeterCorrectionService;
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

/**
 * Entspricht dem Dashboard-Chart CopChartWidget: täglicher COP (App) und
 * hochgerechneter COP (Real) über einen Zeitraum, plus der laufende Tag
 * separat.
 */
#[Name('get_cop_trend')]
#[Title('COP-Verlauf')]
#[Description('Täglicher COP (thermische Energie / Stromverbrauch) der letzten abgeschlossenen Tage, jeweils auf App-Basis und als Hochrechnung auf Zähler-Basis, samt Strom- und Thermikwerten, Durchschnitts-COP des Zeitraums und dem COP des laufenden Tages (bisher). Tage ohne Thermik-Daten werden nicht aufgeführt, sondern gezählt.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetCopTrend extends Tool
{
    use InteractsWithToolInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->min(1)->max(90)
                ->description('Anzahl zurückliegender abgeschlossener Tage bis einschließlich gestern (Standard 14, maximal 90).'),
        ];
    }

    public function handle(Request $request, CopCalculationService $cop, MeterCorrectionService $correction): Response|ResponseFactory
    {
        $days = $this->resolveDays($request, default: 14, max: 90);

        $lastDay = now()->subDay()->startOfDay();
        $firstDay = $lastDay->copy()->subDays($days - 1);

        $intervals = $correction->intervalFactors();

        $series = [];
        $daysWithoutData = 0;
        $copValues = [];

        $cursor = $firstDay->copy();
        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $details = $cop->detailsForDate($cursor);

            if ($details['thermal_kwh'] === null && $details['power_kwh'] === null) {
                $daysWithoutData++;
                $cursor->addDay();

                continue;
            }

            $factor = $correction->factorForDate($cursor, $intervals);
            $realCop = ($factor !== null && $factor > 0 && $details['cop'] !== null)
                ? $details['cop'] / $factor
                : null;

            if ($details['cop'] !== null) {
                $copValues[] = $details['cop'];
            }

            $series[] = [
                'date' => $cursor->toDateString(),
                'cop_app' => $this->round($details['cop'], 2),
                'cop_real_estimate' => $this->round($realCop, 2),
                'thermal_kwh' => $this->round($details['thermal_kwh']),
                'power_app_kwh' => $this->round($details['power_kwh']),
                'correction_factor' => $factor !== null ? $this->round($factor, 4) : null,
            ];

            $cursor->addDay();
        }

        $range = $cop->forRange($firstDay, $lastDay);
        $todayDetails = $cop->detailsForDate(now()->startOfDay());

        return Response::structured([
            'from' => $firstDay->toDateString(),
            'to' => $lastDay->toDateString(),
            'series' => $series,
            'summary' => [
                'period_cop_app' => $this->round($range['cop'], 2),
                'period_days_used' => $range['days_used'],
                'mean_of_daily_cop_app' => $copValues === [] ? null : $this->round(array_sum($copValues) / count($copValues), 2),
                'days_without_any_data' => $daysWithoutData,
            ],
            'today_so_far' => [
                'date' => now()->toDateString(),
                'cop_app' => $this->round($todayDetails['cop'], 2),
                'thermal_kwh' => $this->round($todayDetails['thermal_kwh']),
                'power_app_kwh' => $this->round($todayDetails['power_kwh']),
            ],
            'notes' => [
                'period_cop_app ist die Summe der Thermik geteilt durch die Summe des Stroms über alle Tage mit vollständigen Daten (gewichtet), mean_of_daily_cop_app der einfache Mittelwert der Tages-COPs.',
                'cop_real_estimate = App-COP / Korrekturfaktor des Ablese-Intervalls; null außerhalb abgeschlossener Intervalle.',
            ],
        ]);
    }
}
