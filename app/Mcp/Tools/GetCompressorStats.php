<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use App\Services\ApiLogAggregationService;
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
 * Entspricht den Dashboard-Charts CompressorStartsChartWidget und
 * CompressorHoursChartWidget: die Tageswerte sind die Differenz der
 * kumulativen Zähler (compressor_starts/compressor_hours) zweier
 * aufeinanderfolgender Tage; fehlt einer der beiden Werte, bleibt der Tag
 * eine Lücke statt einer Schätzung.
 */
#[Name('get_compressor_stats')]
#[Title('Kompressor-Statistik')]
#[Description('Kompressorstarts und Betriebsstunden pro Tag im Zeitraum (Differenz der kumulativen Zähler), mit Summen, Ø Starts pro Tag und Ø Laufzeit pro Start. Hilfreich, um Takten oder Abtau-Auffälligkeiten (viele Starts bei kurzer Laufzeit) im Winter zu beobachten. Der laufende Tag ist unvollständig und fließt nicht in die Summen ein.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetCompressorStats extends Tool
{
    use InteractsWithToolInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')
                ->description('Erster Tag des Zeitraums (JJJJ-MM-TT). Standard: 14 Tage bis einschließlich "to".'),
            'to' => $schema->string()->format('date')
                ->description('Letzter Tag des Zeitraums (JJJJ-MM-TT). Standard: heute. Höchstens 92 Tage Zeitraum.'),
        ];
    }

    public function handle(Request $request, ApiLogAggregationService $aggregation): Response|ResponseFactory
    {
        [$from, $to] = $this->resolveDateRange($request, defaultDays: 14, maxDays: 92);

        $today = now()->startOfDay();

        $previousStarts = $aggregation->lastSnapshotValue($from->copy()->subDay(), 'compressor_starts');
        $previousHours = $aggregation->lastSnapshotValue($from->copy()->subDay(), 'compressor_hours');

        $daily = [];
        $sumStarts = 0.0;
        $sumHours = 0.0;
        $daysWithDelta = 0;

        $cursor = $from->copy();
        while ($cursor->lessThanOrEqualTo($to)) {
            $starts = $aggregation->lastSnapshotValue($cursor, 'compressor_starts');
            $hours = $aggregation->lastSnapshotValue($cursor, 'compressor_hours');

            $startsDelta = ($starts !== null && $previousStarts !== null) ? $starts - $previousStarts : null;
            $hoursDelta = ($hours !== null && $previousHours !== null) ? $hours - $previousHours : null;
            $isComplete = $cursor->lessThan($today);

            if ($isComplete && $startsDelta !== null && $hoursDelta !== null) {
                $sumStarts += $startsDelta;
                $sumHours += $hoursDelta;
                $daysWithDelta++;
            }

            if ($starts !== null || $hours !== null) {
                $daily[] = [
                    'date' => $cursor->toDateString(),
                    'is_complete' => $isComplete,
                    'starts' => $startsDelta !== null ? (int) $startsDelta : null,
                    'hours' => $this->round($hoursDelta),
                    'avg_hours_per_start' => ($startsDelta !== null && $startsDelta > 0 && $hoursDelta !== null)
                        ? $this->round($hoursDelta / $startsDelta)
                        : null,
                    'total_starts_counter' => $starts !== null ? (int) $starts : null,
                    'total_hours_counter' => $this->round($hours),
                ];
            }

            $previousStarts = $starts;
            $previousHours = $hours;
            $cursor->addDay();
        }

        return Response::structured([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'daily' => $daily,
            'totals' => [
                'complete_days_with_data' => $daysWithDelta,
                'starts' => $daysWithDelta > 0 ? (int) $sumStarts : null,
                'hours' => $daysWithDelta > 0 ? $this->round($sumHours) : null,
                'avg_starts_per_day' => $daysWithDelta > 0 ? $this->round($sumStarts / $daysWithDelta, 1) : null,
                'avg_hours_per_start' => ($daysWithDelta > 0 && $sumStarts > 0) ? $this->round($sumHours / $sumStarts) : null,
            ],
            'notes' => [
                'Tageswerte sind Differenzen kumulativer Zähler. Fehlt der Zählerstand des Vortags oder des Tages (kein Sync, kein Nachtrag), sind starts/hours null.',
                'Summen und Durchschnitte berücksichtigen nur abgeschlossene Tage, für die Starts und Stunden vorliegen.',
            ],
        ]);
    }
}
