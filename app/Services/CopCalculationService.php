<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * COP (Coefficient of Performance) = abgegebene Waermeenergie / aufgenommene
 * elektrische Energie, aus den taeglichen Summen berechnet statt aus dem
 * API-eigenen SPF-Feld (das laut Fahrplan Abschnitt 4.5 noch 0 ist, da die
 * Anlage keine volle Berechnungsperiode durchlaufen hat).
 *
 * Automatisch fuer jeden Tag verfuegbar, an dem sowohl ein Stromwert als auch
 * ein Thermik-Wert vorliegen - egal ob per Sync oder manuellem Nachtrag ueber
 * die "Anlagenwerte"-Oberflaeche (thermal_heating_kwh/thermal_dhw_kwh haben
 * kein day[]-Array in der API, lassen sich aber nachtraeglich auf jeder
 * api_log-Zeile erfassen, siehe Fahrplan Abschnitt 4.10/4.12/4.17).
 */
class CopCalculationService
{
    public function __construct(private readonly ApiLogAggregationService $aggregation)
    {
    }

    public function forDate(Carbon $date): ?float
    {
        $thermalHeating = $this->aggregation->lastSnapshotValue($date, 'thermal_heating_kwh');
        $thermalDhw = $this->aggregation->lastSnapshotValue($date, 'thermal_dhw_kwh');
        $power = $this->aggregation->dailyPowerValue($date, 'power_total_kwh');

        if ($power === null || $power <= 0) {
            return null;
        }

        if ($thermalHeating === null && $thermalDhw === null) {
            return null;
        }

        $thermal = ($thermalHeating ?? 0) + ($thermalDhw ?? 0);

        return round($thermal / $power, 3);
    }

    /**
     * COP ueber mehrere Tage, nur aus Tagen mit vollstaendigen Daten berechnet.
     *
     * @return array{cop: ?float, days_used: int, days_skipped: array<string>}
     */
    public function forRange(Carbon $from, Carbon $to): array
    {
        $thermalSum = 0.0;
        $powerSum = 0.0;
        $daysUsed = 0;
        $daysSkipped = [];

        $cursor = $from->copy()->startOfDay();
        $lastDay = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $thermalHeating = $this->aggregation->lastSnapshotValue($cursor, 'thermal_heating_kwh');
            $thermalDhw = $this->aggregation->lastSnapshotValue($cursor, 'thermal_dhw_kwh');
            $power = $this->aggregation->dailyPowerValue($cursor, 'power_total_kwh');

            if ($power === null || ($thermalHeating === null && $thermalDhw === null)) {
                $daysSkipped[] = $cursor->toDateString();
                $cursor->addDay();

                continue;
            }

            $thermalSum += ($thermalHeating ?? 0) + ($thermalDhw ?? 0);
            $powerSum += $power;
            $daysUsed++;

            $cursor->addDay();
        }

        return [
            'cop' => $powerSum > 0 ? round($thermalSum / $powerSum, 3) : null,
            'days_used' => $daysUsed,
            'days_skipped' => $daysSkipped,
        ];
    }
}
