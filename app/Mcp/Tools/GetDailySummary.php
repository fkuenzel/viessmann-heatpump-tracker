<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use App\Models\WeatherLog;
use App\Services\ApiLogAggregationService;
use App\Services\CopCalculationService;
use App\Services\MeterCorrectionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
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
 * Tageswerte auf App-Basis (wie ConsumptionChartWidget/CopChartWidget),
 * ergänzt um eine Hochrechnung auf Zähler-Basis, sofern der Tag in einem
 * abgeschlossenen Ablese-Intervall liegt (Fahrplan Abschnitt 4.22).
 */
#[Name('get_daily_summary')]
#[Title('Tageszusammenfassung')]
#[Description('Stromverbrauch (Heizen, Warmwasser, gesamt), thermische Energie, COP, Außentemperatur und Kompressor-Zählerstände für einen einzelnen Tag auf App-Basis, plus eine Hochrechnung auf Zähler-Basis, falls der Tag in einem abgeschlossenen Ablese-Intervall liegt. Ohne Datum wird der heutige (unvollständige) Tag geliefert.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetDailySummary extends Tool
{
    use InteractsWithToolInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->format('date')
                ->description('Datum im Format JJJJ-MM-TT. Standard: heute (Tag ist dann noch unvollständig). Nicht in der Zukunft.'),
        ];
    }

    public function handle(
        Request $request,
        ApiLogAggregationService $aggregation,
        CopCalculationService $cop,
        MeterCorrectionService $correction,
    ): Response|ResponseFactory {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $date = isset($validated['date'])
            ? Carbon::createFromFormat('Y-m-d', $validated['date'])->startOfDay()
            : now()->startOfDay();

        $details = $cop->detailsForDate($date);

        $powerHeating = $aggregation->dailyPowerValue($date, 'power_heating_kwh');
        $powerDhw = $aggregation->dailyPowerValue($date, 'power_dhw_kwh');
        $thermalHeating = $aggregation->lastSnapshotValue($date, 'thermal_heating_kwh');
        $thermalDhw = $aggregation->lastSnapshotValue($date, 'thermal_dhw_kwh');

        $starts = $aggregation->lastSnapshotValue($date, 'compressor_starts');
        $startsPreviousDay = $aggregation->lastSnapshotValue($date->copy()->subDay(), 'compressor_starts');

        $archive = WeatherLog::query()
            ->where('source', 'archive_daily')
            ->whereBetween('timestamp', [$date, $date->copy()->endOfDay()])
            ->first();

        // Hochrechnung auf Zähler-Basis wie in den Dashboard-Charts: App-
        // Tageswert mal Korrekturfaktor des umschließenden Ablese-Intervalls,
        // realer COP = App-COP / Korrekturfaktor. Außerhalb eines
        // abgeschlossenen Intervalls gibt es keine Schätzung (null).
        $factor = $correction->factorForDate($date, $correction->intervalFactors());
        $hasFactor = $factor !== null && $factor > 0;

        return Response::structured([
            'date' => $date->toDateString(),
            'is_complete' => $date->lessThan(now()->startOfDay()),
            'basis' => 'app',
            'power_kwh' => [
                'heating' => $this->round($powerHeating),
                'dhw' => $this->round($powerDhw),
                'total' => $this->round($details['power_kwh']),
            ],
            'thermal_kwh' => [
                'heating' => $this->round($thermalHeating),
                'dhw' => $this->round($thermalDhw),
                'total' => $this->round($details['thermal_kwh']),
            ],
            'cop' => $this->round($details['cop'], 2),
            'outdoor_temperature_celsius' => [
                'plant' => $this->round($aggregation->lastSnapshotValue($date, 'outdoor_temp'), 1),
                'external_mean' => $this->round($archive ? (float) $archive->temperature : null, 1),
                'external_humidity_mean_percent' => $this->round($archive ? (float) $archive->humidity : null, 0),
            ],
            'compressor' => [
                'total_starts_counter' => $starts !== null ? (int) $starts : null,
                'total_hours_counter' => $this->round($aggregation->lastSnapshotValue($date, 'compressor_hours')),
                'starts_that_day' => ($starts !== null && $startsPreviousDay !== null) ? (int) ($starts - $startsPreviousDay) : null,
            ],
            'meter_based_estimate' => [
                'correction_factor' => $hasFactor ? $this->round($factor, 4) : null,
                'power_heating_kwh' => $hasFactor ? $this->round($powerHeating !== null ? $powerHeating * $factor : null) : null,
                'power_dhw_kwh' => $hasFactor ? $this->round($powerDhw !== null ? $powerDhw * $factor : null) : null,
                'power_total_kwh' => $hasFactor ? $this->round($details['power_kwh'] !== null ? $details['power_kwh'] * $factor : null) : null,
                'cop' => $hasFactor ? $this->round($details['cop'] !== null ? $details['cop'] / $factor : null, 2) : null,
                'note' => $hasFactor
                    ? 'Hochrechnung: App-Tageswert mal Korrekturfaktor des umschließenden Ablese-Intervalls, keine tagesgenaue Messung.'
                    : 'Keine Hochrechnung möglich: Der Tag liegt in keinem abgeschlossenen Ablese-Intervall.',
            ],
        ]);
    }
}
