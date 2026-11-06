<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use App\Models\Setting;
use App\Models\WeatherForecastLog;
use App\Models\WeatherLog;
use App\Services\ApiLogAggregationService;
use App\Services\WeatherSyncService;
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
 * Entspricht der Seite "Wetterdaten" (Temperaturvergleich pro Tag plus
 * Prognose): Außentemperatur der Anlage gegenüber den Tagesmittelwerten von
 * Open-Meteo am hinterlegten Standort.
 */
#[Name('get_weather_comparison')]
#[Title('Wettervergleich')]
#[Description('Tagesmittel der externen Außentemperatur und Luftfeuchtigkeit (Open-Meteo, Standort der Anlage) im Vergleich zur Außentemperatur des Anlagensensors, mit Abweichung (Anlage minus extern), sowie die aktuelle Kurzfrist-Prognose. Setzt einen hinterlegten Wetter-Standort voraus.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetWeatherComparison extends Tool
{
    use InteractsWithToolInput;

    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->min(1)->max(90)
                ->description('Anzahl zurückliegender Tage bis einschließlich gestern (Standard 14, maximal 90).'),
        ];
    }

    public function handle(Request $request, ApiLogAggregationService $aggregation, WeatherSyncService $weather): Response|ResponseFactory
    {
        if (! $weather->isLocationConfigured()) {
            return Response::error('Kein Wetter-Standort hinterlegt. Der Standort wird in der Anwendung unter "Einstellungen" per Ortsname eingerichtet.');
        }

        $days = $this->resolveDays($request, default: 14, max: 90);

        $lastDay = now()->subDay()->startOfDay();
        $firstDay = $lastDay->copy()->subDays($days - 1);

        $archive = WeatherLog::query()
            ->where('source', 'archive_daily')
            ->whereBetween('timestamp', [$firstDay, $lastDay->copy()->endOfDay()])
            ->orderBy('timestamp')
            ->get();

        $comparison = [];
        $deviations = [];

        foreach ($archive as $row) {
            $date = $row->timestamp->copy()->startOfDay();
            $plantTemp = $aggregation->averageOutdoorTemp($date);
            $externalTemp = (float) $row->temperature;
            $deviation = $plantTemp !== null ? $plantTemp - $externalTemp : null;

            if ($deviation !== null) {
                $deviations[] = $deviation;
            }

            $comparison[] = [
                'date' => $date->toDateString(),
                'external_temp_celsius' => $this->round($externalTemp, 1),
                'external_humidity_percent' => $this->round((float) $row->humidity, 0),
                'plant_temp_celsius' => $this->round($plantTemp, 1),
                'deviation_celsius' => $this->round($deviation, 1),
            ];
        }

        $horizonDays = (int) Setting::get('forecast_horizon_days', 3);

        $forecast = WeatherForecastLog::query()
            ->where('forecast_for', '>=', now()->startOfDay())
            ->orderByDesc('fetched_at')
            ->get()
            ->unique('forecast_for')
            ->sortBy('forecast_for')
            ->take($horizonDays)
            ->map(fn (WeatherForecastLog $day): array => [
                'date' => Carbon::parse($day->forecast_for)->toDateString(),
                'temp_celsius' => $this->round((float) $day->forecast_temperature, 1),
                'humidity_percent' => $this->round((float) $day->forecast_humidity, 0),
                'fetched_at' => $this->iso(Carbon::parse($day->fetched_at)),
            ])
            ->values()
            ->all();

        return Response::structured([
            'from' => $firstDay->toDateString(),
            'to' => $lastDay->toDateString(),
            'daily' => $comparison,
            'summary' => [
                'days_with_external_data' => count($comparison),
                'days_with_plant_data' => count($deviations),
                'mean_deviation_celsius' => $deviations === [] ? null : $this->round(array_sum($deviations) / count($deviations), 1),
            ],
            'forecast' => $forecast,
            'notes' => [
                'plant_temp_celsius ist der Mittelwert der Anlagen-Außentemperatur aus den Sync-Abrufen des Tages; ohne Sync oder Nachtrag ist er null.',
                'Die externen Tagesmittel stammen aus dem Open-Meteo-Archiv und liegen erst nach dem nächtlichen Abruf für den Vortag vor.',
            ],
        ]);
    }
}
