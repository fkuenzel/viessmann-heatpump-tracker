<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use App\Models\ApiLog;
use App\Models\WeatherLog;
use App\Services\ApiLogAggregationService;
use App\Services\CopCalculationService;
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
 * Aktueller Stand der Anlage aus der Datenbank (letzter Sync), keine
 * Live-Abfrage der Viessmann-API - dadurch kein Rate-Limit-Verbrauch und
 * keine Abhängigkeit von der Erreichbarkeit der Viessmann-Server. Wie alt
 * die Daten sind, steht in last_sync.age_minutes.
 */
#[Name('get_current_status')]
#[Title('Aktueller Anlagenstatus')]
#[Description('Aktueller Stand der Wärmepumpe aus dem letzten Sync: Zeitpunkt und Alter des letzten Datenabrufs, Verbrauch und thermische Energie des laufenden Tages (bisher), Außentemperatur (Anlage und extern), Gerätestatus (Vor-/Rücklauf, Druck, Pumpe, Verdichter) und Kompressor-Gesamtzähler. Keine Live-Abfrage der Viessmann-API.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetCurrentStatus extends Tool
{
    use InteractsWithToolInput;

    public function handle(Request $request, ApiLogAggregationService $aggregation, CopCalculationService $cop): Response|ResponseFactory
    {
        $now = now();
        $today = $now->copy()->startOfDay();

        // Nur Sync-Zeilen (scheduled/manual) zählen als "letzter Sync" - ein
        // manueller Nachtrag (source=backfill) sagt nichts darüber aus, wie
        // aktuell die Anlagendaten sind.
        $latestSync = ApiLog::query()
            ->whereIn('source', ['scheduled', 'manual'])
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->first();

        $deviceRow = ApiLog::query()
            ->whereIn('source', ['scheduled', 'manual'])
            ->whereNotNull('flow_temp')
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->first();

        $todayDetails = $cop->detailsForDate($today);

        $thermalHeating = $aggregation->lastSnapshotValue($today, 'thermal_heating_kwh');
        $thermalDhw = $aggregation->lastSnapshotValue($today, 'thermal_dhw_kwh');

        $compressorStarts = $aggregation->lastSnapshotValue($today, 'compressor_starts');

        $plantTemp = $aggregation->lastSnapshotValue($now, 'outdoor_temp');
        $plantTempAt = $aggregation->lastSnapshotTimestamp($now, 'outdoor_temp');

        $externalWeather = WeatherLog::query()
            ->where('source', 'live_snapshot')
            ->latest('timestamp')
            ->first();

        return Response::structured([
            'as_of' => $this->iso($now),
            'last_sync' => [
                'at' => $this->iso($latestSync?->timestamp),
                'age_minutes' => $latestSync ? (int) $latestSync->timestamp->diffInMinutes($now) : null,
                'source' => $latestSync?->source,
            ],
            'today_so_far' => [
                'date' => $today->toDateString(),
                'is_complete' => false,
                'power_heating_kwh' => $this->round($aggregation->dailyPowerValue($today, 'power_heating_kwh')),
                'power_dhw_kwh' => $this->round($aggregation->dailyPowerValue($today, 'power_dhw_kwh')),
                'power_total_kwh' => $this->round($todayDetails['power_kwh']),
                'thermal_heating_kwh' => $this->round($thermalHeating),
                'thermal_dhw_kwh' => $this->round($thermalDhw),
                'thermal_total_kwh' => $this->round($todayDetails['thermal_kwh']),
                'cop_so_far' => $this->round($todayDetails['cop'], 2),
            ],
            'outdoor_temperature' => [
                'plant_celsius' => $this->round($plantTemp, 1),
                'plant_at' => $this->iso($plantTempAt),
                'external_celsius' => $this->round($this->asFloat($externalWeather?->temperature), 1),
                'external_humidity_percent' => $this->round($this->asFloat($externalWeather?->humidity), 0),
                'external_at' => $this->iso($externalWeather?->timestamp),
            ],
            'device' => [
                'status_at' => $this->iso($deviceRow?->timestamp),
                'flow_temp_celsius' => $this->round($this->asFloat($deviceRow?->flow_temp), 1),
                'return_temp_celsius' => $this->round($this->asFloat($deviceRow?->return_temp), 1),
                'secondary_flow_temp_celsius' => $this->round($this->asFloat($deviceRow?->secondary_flow_temp), 1),
                'system_pressure_bar' => $this->round($this->asFloat($deviceRow?->system_pressure), 1),
                'volumetric_flow_l_per_h' => $this->round($this->asFloat($deviceRow?->volumetric_flow), 0),
                'internal_pump_on' => match ($deviceRow?->internal_pump_status) {
                    'on' => true,
                    'off' => false,
                    default => null,
                },
                'compressor_active' => $deviceRow?->compressor_active,
                'compressor_phase' => $deviceRow?->compressor_phase,
            ],
            'compressor_counters' => [
                'total_starts' => $compressorStarts !== null ? (int) $compressorStarts : null,
                'total_hours' => $this->round($aggregation->lastSnapshotValue($today, 'compressor_hours')),
            ],
        ]);
    }

    private function asFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
