<?php

namespace App\Services;

use App\Models\MeterReading;
use App\Models\PriceHistory;

/**
 * Kostenberechnung auf Basis der realen Zaehler-Differenz (HT/NT) zwischen
 * zwei Ablesungen und dem zum Ende des Intervalls gueltigen Tarif aus
 * price_history.
 *
 * Vereinfachung (bewusst, Stand 28.10.2026): Bei einem Tarifwechsel
 * mitten im Intervall wird der am Intervallende gueltige Tarif auf den
 * gesamten Zeitraum angewendet, keine anteilige Aufteilung - Fabian liest
 * den Zaehler ohnehin bei jedem Tarifwechsel ab, der Fall soll praktisch
 * nicht vorkommen. Tritt er doch auf, wird per `tariff_changed_during_interval`
 * gewarnt, damit es nicht unbemerkt falsch gerechnet wird.
 */
class CostCalculationService
{
    /**
     * @return array{
     *     delta_ht_kwh: float,
     *     delta_nt_kwh: float,
     *     tariff: ?PriceHistory,
     *     energy_cost: ?float,
     *     base_fee_cost: ?float,
     *     total_cost: ?float,
     *     tariff_changed_during_interval: bool,
     * }
     */
    public function calculate(MeterReading $from, MeterReading $to): array
    {
        $deltaHt = (float) $to->counter_ht - (float) $from->counter_ht;
        $deltaNt = (float) $to->counter_nt - (float) $from->counter_nt;

        $tariff = PriceHistory::validAt($to->timestamp);

        $tariffChangedDuringInterval = PriceHistory::query()
            ->where('valid_from', '>', $from->timestamp)
            ->where('valid_from', '<=', $to->timestamp)
            ->exists();

        if (! $tariff) {
            return [
                'delta_ht_kwh' => round($deltaHt, 3),
                'delta_nt_kwh' => round($deltaNt, 3),
                'tariff' => null,
                'energy_cost' => null,
                'base_fee_cost' => null,
                'total_cost' => null,
                'tariff_changed_during_interval' => $tariffChangedDuringInterval,
            ];
        }

        $energyCost = ($deltaHt * (float) $tariff->ht_price) + ($deltaNt * (float) $tariff->nt_price);

        $intervalDays = $from->timestamp->diffInSeconds($to->timestamp) / 86400;
        $periodDays = $tariff->base_fee_period === 'yearly' ? 365 : 30;
        $baseFeeCost = ((float) $tariff->base_fee / $periodDays) * $intervalDays;

        return [
            'delta_ht_kwh' => round($deltaHt, 3),
            'delta_nt_kwh' => round($deltaNt, 3),
            'tariff' => $tariff,
            'energy_cost' => round($energyCost, 2),
            'base_fee_cost' => round($baseFeeCost, 2),
            'total_cost' => round($energyCost + $baseFeeCost, 2),
            'tariff_changed_during_interval' => $tariffChangedDuringInterval,
        ];
    }
}
