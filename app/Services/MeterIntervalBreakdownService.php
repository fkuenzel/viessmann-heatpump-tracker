<?php

namespace App\Services;

use App\Models\MeterReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Berechnet pro Ablese-Intervall die reale Aufteilung Heizen/Warmwasser,
 * den echten COP auf Zähler-Basis sowie die Kosten (Fahrplan Abschnitt
 * 4.21/4.24/4.25/4.27/4.28).
 *
 * Die Logik stammt ursprünglich aus App\Filament\Widgets\
 * MeterIntervalBreakdownWidget und wurde mit Version 1.1.0 unverändert in
 * diesen Service ausgelagert, damit das Dashboard-Widget und der MCP-Server
 * (App\Mcp\Tools\GetIntervalBreakdown) exakt dieselben Werte liefern, ohne
 * die Berechnung zweimal zu pflegen.
 */
class MeterIntervalBreakdownService
{
    /**
     * Thermische Energie ist ein Tages-Snapshot ohne untertägige Auflösung
     * (Abschnitt 4.10) - bei Intervallen unter dieser Dauer wird dem
     * Intervall trotzdem der volle Tageswert zugerechnet, was den COP
     * (Thermik / sehr kleiner Zähler-Verbrauch) stark verzerrt. Fund
     * 29.10.2026 (Fabians zweite Ablesung desselben Tages ergab ein
     * 0,23-Tage-Intervall mit einem COP von über 20) - unterhalb dieser
     * Schwelle gilt "Echter COP" als nicht aussagekräftig.
     */
    public const MIN_RELIABLE_INTERVAL_DAYS = 1.0;

    public function __construct(
        private readonly MeterCorrectionService $correction,
        private readonly ApiLogAggregationService $aggregation,
        private readonly CostCalculationService $cost,
    ) {
    }

    public function previousReading(MeterReading $record): ?MeterReading
    {
        return MeterReading::query()
            ->where('timestamp', '<', $record->timestamp)
            ->orderByDesc('timestamp')
            ->first();
    }

    /**
     * Alle Zählerstände, die ein Ablese-Intervall abschließen (also alle
     * außer der allerersten Ablesung), optional auf den Ablesezeitpunkt
     * eingegrenzt, chronologisch aufsteigend.
     *
     * @return Collection<int, MeterReading>
     */
    public function intervalEndReadings(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $firstId = MeterReading::query()->orderBy('timestamp')->value('id');

        return MeterReading::query()
            ->when($firstId, fn ($query) => $query->where('id', '!=', $firstId))
            ->when($from, fn ($query) => $query->where('timestamp', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($query) => $query->where('timestamp', '<=', $to->copy()->endOfDay()))
            ->orderBy('timestamp')
            ->get();
    }

    /**
     * @return array{
     *     duration_days: ?float,
     *     avg_kwh_per_day: ?float,
     *     meter_kwh: ?float,
     *     delta_ht_kwh: ?float,
     *     delta_nt_kwh: ?float,
     *     api_kwh: ?float,
     *     correction_factor: ?float,
     *     heating_percent: ?float,
     *     dhw_percent: ?float,
     *     heating_percent_cumulative: ?float,
     *     dhw_percent_cumulative: ?float,
     *     real_heating_kwh: ?float,
     *     real_dhw_kwh: ?float,
     *     thermal_kwh: ?float,
     *     real_cop: ?float,
     *     app_heating_kwh: ?float,
     *     app_dhw_kwh: ?float,
     *     app_cop: ?float,
     *     total_cost: ?float,
     *     heating_cost: ?float,
     *     dhw_cost: ?float,
     *     tariff_changed_during_interval: ?bool,
     *     reliable: ?bool,
     * }
     */
    public function breakdown(MeterReading $record): array
    {
        $empty = [
            'duration_days' => null,
            'avg_kwh_per_day' => null,
            'meter_kwh' => null,
            'delta_ht_kwh' => null,
            'delta_nt_kwh' => null,
            'api_kwh' => null,
            'correction_factor' => null,
            'heating_percent' => null,
            'dhw_percent' => null,
            'heating_percent_cumulative' => null,
            'dhw_percent_cumulative' => null,
            'real_heating_kwh' => null,
            'real_dhw_kwh' => null,
            'thermal_kwh' => null,
            'real_cop' => null,
            'app_heating_kwh' => null,
            'app_dhw_kwh' => null,
            'app_cop' => null,
            'total_cost' => null,
            'heating_cost' => null,
            'dhw_cost' => null,
            'tariff_changed_during_interval' => null,
            'reliable' => null,
        ];

        $previous = $this->previousReading($record);

        if (! $previous) {
            return $empty;
        }

        $result = $this->correction->calculate($previous, $record);
        $meterKwh = $result['meter_kwh'];
        $apiKwh = $result['api_kwh'];

        $durationDaysRaw = $previous->timestamp->diffInSeconds($record->timestamp) / 86400;
        $avgKwhPerDay = $durationDaysRaw > 0 ? $meterKwh / $durationDaysRaw : null;

        $heatingSum = $this->aggregation->weightedSum($previous->timestamp, $record->timestamp, 'power_heating_kwh')['value'];
        $dhwSum = $this->aggregation->weightedSum($previous->timestamp, $record->timestamp, 'power_dhw_kwh')['value'];

        $heatingPercent = ($apiKwh !== null && $apiKwh > 0 && $heatingSum !== null)
            ? ($heatingSum / $apiKwh) * 100
            : null;
        $dhwPercent = ($apiKwh !== null && $apiKwh > 0 && $dhwSum !== null)
            ? ($dhwSum / $apiKwh) * 100
            : null;

        $realHeatingKwh = ($heatingPercent !== null && $meterKwh !== null)
            ? $meterKwh * $heatingPercent / 100
            : null;
        $realDhwKwh = ($dhwPercent !== null && $meterKwh !== null)
            ? $meterKwh * $dhwPercent / 100
            : null;

        $thermalKwh = $this->thermalSum($previous->timestamp, $record->timestamp);

        $realCop = ($thermalKwh !== null && $meterKwh !== null && $meterKwh > 0)
            ? $thermalKwh / $meterKwh
            : null;

        $appCop = ($thermalKwh !== null && $apiKwh !== null && $apiKwh > 0)
            ? $thermalKwh / $apiKwh
            : null;

        [$heatingPercentCumulative, $dhwPercentCumulative] = $this->cumulativePercents($record);

        // Kosten proportional nach Heizen-%/WW%-Anteil des Intervalls
        // aufgeteilt (inkl. anteiliger Grundgebühr) - wie in Fabians
        // bisheriger Excel-Tabelle (Spalte "Kosten Heizen/WW"), Fahrplan
        // Abschnitt 4.27. CostCalculationService::calculate() liefert nur
        // den Gesamtbetrag des Intervalls (Phase 4), die Aufteilung ist neu.
        $costResult = $this->cost->calculate($previous, $record);
        $totalCost = $costResult['total_cost'];
        $deltaHtKwh = $costResult['delta_ht_kwh'];
        $deltaNtKwh = $costResult['delta_nt_kwh'];
        $heatingCost = ($totalCost !== null && $heatingPercent !== null)
            ? round($totalCost * $heatingPercent / 100, 2)
            : null;
        $dhwCost = ($totalCost !== null && $dhwPercent !== null)
            ? round($totalCost * $dhwPercent / 100, 2)
            : null;

        return [
            'duration_days' => round($durationDaysRaw, 2),
            'avg_kwh_per_day' => $avgKwhPerDay !== null ? round($avgKwhPerDay, 2) : null,
            'meter_kwh' => $meterKwh,
            'delta_ht_kwh' => round($deltaHtKwh, 2),
            'delta_nt_kwh' => round($deltaNtKwh, 2),
            'api_kwh' => $apiKwh,
            'correction_factor' => $result['correction_factor'],
            'heating_percent' => $heatingPercent !== null ? round($heatingPercent, 2) : null,
            'dhw_percent' => $dhwPercent !== null ? round($dhwPercent, 2) : null,
            'heating_percent_cumulative' => $heatingPercentCumulative,
            'dhw_percent_cumulative' => $dhwPercentCumulative,
            'real_heating_kwh' => $realHeatingKwh !== null ? round($realHeatingKwh, 2) : null,
            'real_dhw_kwh' => $realDhwKwh !== null ? round($realDhwKwh, 2) : null,
            'thermal_kwh' => $thermalKwh !== null ? round($thermalKwh, 2) : null,
            'real_cop' => $realCop !== null ? round($realCop, 2) : null,
            'app_heating_kwh' => $heatingSum !== null ? round($heatingSum, 2) : null,
            'app_dhw_kwh' => $dhwSum !== null ? round($dhwSum, 2) : null,
            'app_cop' => $appCop !== null ? round($appCop, 2) : null,
            'total_cost' => $totalCost,
            'heating_cost' => $heatingCost,
            'dhw_cost' => $dhwCost,
            'tariff_changed_during_interval' => $costResult['tariff_changed_during_interval'],
            'reliable' => $durationDaysRaw >= self::MIN_RELIABLE_INTERVAL_DAYS,
        ];
    }

    /**
     * Kumulierter Heizen-%/WW%-Anteil seit Messbeginn (früheste Ablesung)
     * bis einschließlich diesem Intervall-Ende - analog zur GESAMT-Zeile in
     * Fabians bisheriger Excel-Tabelle, als helle Zusatzzeile unter dem
     * Intervall-eigenen Anteil (Fabians Entscheidung, 29.10.2026).
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function cumulativePercents(MeterReading $record): array
    {
        $firstTimestamp = MeterReading::query()->orderBy('timestamp')->value('timestamp');

        if (! $firstTimestamp) {
            return [null, null];
        }

        $firstTimestamp = Carbon::parse($firstTimestamp);

        $heatingSum = $this->aggregation->weightedSum($firstTimestamp, $record->timestamp, 'power_heating_kwh')['value'];
        $dhwSum = $this->aggregation->weightedSum($firstTimestamp, $record->timestamp, 'power_dhw_kwh')['value'];
        $totalSum = $this->aggregation->weightedSum($firstTimestamp, $record->timestamp, 'power_total_kwh')['value'];

        if ($totalSum === null || $totalSum <= 0) {
            return [null, null];
        }

        return [
            $heatingSum !== null ? round(($heatingSum / $totalSum) * 100, 2) : null,
            $dhwSum !== null ? round(($dhwSum / $totalSum) * 100, 2) : null,
        ];
    }

    /**
     * Thermische Energie (Heizen + Warmwasser) über das Intervall, als
     * einfache Tagessumme wie in CopCalculationService::forRange() - keine
     * Rand-Intervall-Gewichtung, da Thermik ohnehin als "24 Std. Messung"
     * pro Kalendertag geführt wird (Fahrplan Abschnitt 4.10/4.16).
     */
    private function thermalSum(Carbon $from, Carbon $to): ?float
    {
        $sum = 0.0;
        $hasValue = false;

        $cursor = $from->copy()->startOfDay();
        $lastDay = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $heating = $this->aggregation->lastSnapshotValue($cursor, 'thermal_heating_kwh');
            $dhw = $this->aggregation->lastSnapshotValue($cursor, 'thermal_dhw_kwh');

            if ($heating !== null || $dhw !== null) {
                $sum += ($heating ?? 0) + ($dhw ?? 0);
                $hasValue = true;
            }

            $cursor->addDay();
        }

        return $hasValue ? $sum : null;
    }
}
