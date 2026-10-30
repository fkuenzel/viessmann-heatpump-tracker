<?php

namespace App\Filament\Widgets;

use App\Models\MeterReading;
use App\Services\ApiLogAggregationService;
use App\Services\CostCalculationService;
use App\Services\MeterCorrectionService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Kumulierte Gesamtwerte seit der ersten Ablesung (analog zur GESAMT-Zeile
 * in Fabians bisheriger Excel-Tabelle "HeizStrom", Fahrplan Abschnitt 4.28):
 * Summe ueber ALLE Ablese-Intervalle statt nur des letzten. Als kompakte
 * Stat-Kacheln (aehnlich WeatherWidget) statt einer zusaetzlichen
 * Summenzeile in der Detailtabelle - Fabians Entscheidung, 29.10.2026, um
 * MeterIntervalBreakdownWidget nicht weiter zu ueberladen.
 *
 * Fund + Fix (29.10.2026, Fabians zweite Zaehlerstand-Erfassung desselben
 * Tages): Thermik wird pro Intervall tagesweise summiert (thermalSum(),
 * keine Rand-Intervall-Gewichtung, siehe dortigen Kommentar). Grenzt ein
 * Intervall an das naechste exakt an einem Kalendertag (Normalfall, da
 * Ablesezeitpunkte fast nie auf Mitternacht fallen), wurde dieser
 * gemeinsame Tag bisher in BEIDEN Intervallen mitgezaehlt - die kumulierte
 * Summe war dadurch systematisch zu hoch (an jeder Intervallgrenze, nicht
 * nur bei besonders kurzen Intervallen). Fiel erst auf, als zwei
 * Ablesungen am selben Tag (29.10., 10:47 und 16:18) ein zusaetzliches,
 * sehr kurzes Intervall erzeugten, dessen einziger Tag (29.10.) bereits vom
 * vorherigen Intervall (25.10.-29.10.) mitgezaehlt worden war - dadurch
 * sprang "Thermische Energie gesamt"/"Ø COP gesamt" sichtbar zu stark nach
 * oben. Fix: ein gemeinsamer $countedThermalDays-Satz ueber den gesamten
 * Lauf, jeder Kalendertag zaehlt dadurch nur noch im ERSTEN (chronologisch
 * fruehesten) Intervall, das ihn enthaelt - bei an Kalendertagen
 * angrenzenden Intervallen ist das genau das Intervall, in dem der Tag
 * endet, nicht das, in dem er beginnt.
 */
class MeterTotalsWidget extends StatsOverviewWidget
{
    // Ganz oben, noch vor AnnotationsWidget (Fabians Wunsch, 29.10.2026) -
    // Gesamtwerte sollen das Allererste sein, was auf dem Dashboard zu
    // sehen ist.
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        $readings = MeterReading::query()->orderBy('timestamp')->get();

        if ($readings->count() < 2) {
            return [
                Stat::make('Gesamtverbrauch (Zähler)', '–')
                    ->description('Mindestens 2 Zählerstände nötig'),
            ];
        }

        $correction = app(MeterCorrectionService::class);
        $cost = app(CostCalculationService::class);
        $aggregation = app(ApiLogAggregationService::class);

        $totalMeterKwh = 0.0;
        $totalCost = 0.0;
        $hasCost = false;
        $totalThermalKwh = 0.0;
        $hasThermal = false;

        // Siehe Klassenkommentar: verhindert, dass ein Kalendertag an einer
        // Intervallgrenze doppelt gezaehlt wird.
        $countedThermalDays = [];

        foreach ($readings as $index => $reading) {
            if ($index === 0) {
                continue;
            }

            $previous = $readings[$index - 1];

            $correctionResult = $correction->calculate($previous, $reading);
            $totalMeterKwh += $correctionResult['meter_kwh'];

            $costResult = $cost->calculate($previous, $reading);

            if ($costResult['total_cost'] !== null) {
                $totalCost += $costResult['total_cost'];
                $hasCost = true;
            }

            $thermal = $this->thermalSum($previous->timestamp, $reading->timestamp, $aggregation, $countedThermalDays);

            if ($thermal !== null) {
                $totalThermalKwh += $thermal;
                $hasThermal = true;
            }
        }

        $averageCop = ($hasThermal && $totalMeterKwh > 0) ? $totalThermalKwh / $totalMeterKwh : null;

        return [
            Stat::make('Gesamtverbrauch (Zähler)', number_format($totalMeterKwh, 2, ',', '.') . ' kWh')
                ->description('seit ' . $readings->first()->timestamp->format('d.m.Y')),
            Stat::make('Gesamtkosten', $hasCost ? number_format($totalCost, 2, ',', '.') . ' €' : '–'),
            Stat::make('Thermische Energie gesamt', $hasThermal ? number_format($totalThermalKwh, 2, ',', '.') . ' kWh' : '–'),
            Stat::make('Ø COP gesamt', $averageCop !== null ? number_format($averageCop, 2, ',', '.') : '–'),
        ];
    }

    /**
     * Thermische Energie (Heizen + Warmwasser) ueber ein Intervall, als
     * einfache Tagessumme wie in CopCalculationService::forRange() - keine
     * Rand-Intervall-Gewichtung, da Thermik als "24 Std. Messung" gefuehrt
     * wird (Fahrplan Abschnitt 4.10/4.16). Dieselbe Logik wie in
     * MeterIntervalBreakdownWidget, hier fuer die kumulierte Summe.
     *
     * $countedDays wird ueber alle Intervalle dieses Widget-Laufs geteilt
     * (per Referenz) und verhindert, dass ein Kalendertag an einer
     * Intervallgrenze doppelt in die Gesamtsumme einfliesst (siehe
     * Klassenkommentar).
     *
     * @param  array<string, true>  $countedDays
     */
    private function thermalSum(Carbon $from, Carbon $to, ApiLogAggregationService $aggregation, array &$countedDays): ?float
    {
        $sum = 0.0;
        $hasValue = false;

        $cursor = $from->copy()->startOfDay();
        $lastDay = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $dayKey = $cursor->toDateString();

            if (! isset($countedDays[$dayKey])) {
                $heating = $aggregation->lastSnapshotValue($cursor, 'thermal_heating_kwh');
                $dhw = $aggregation->lastSnapshotValue($cursor, 'thermal_dhw_kwh');

                if ($heating !== null || $dhw !== null) {
                    $sum += ($heating ?? 0) + ($dhw ?? 0);
                    $hasValue = true;
                }

                $countedDays[$dayKey] = true;
            }

            $cursor->addDay();
        }

        return $hasValue ? $sum : null;
    }
}
