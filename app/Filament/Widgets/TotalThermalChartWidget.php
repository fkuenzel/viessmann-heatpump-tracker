<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ShowsPeriodAverage;
use App\Services\ApiLogAggregationService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * Taegliche thermische Gesamtenergie ueber einen waehlbaren Zeitraum.
 * Ersetzt seit Fabians Wunsch (30.10.2026) die Gesamt-Linie aus dem fruehren
 * ThermalProductionChartWidget, das Heizen/Warmwasser/Gesamt in einem
 * einzigen Chart zeigte - ein Chart pro Nutzungsart passt sowohl besser zur
 * App-Optik (ein Chart = eine gefuellte Linie) als auch zum neuen Layout
 * neben den Strom-Charts (siehe TotalConsumptionChartWidget).
 *
 * Im Gegensatz zum Stromverbrauch gibt es fuer Thermik kein Real/App-Paar -
 * es gibt keinen physischen Waermemengenzaehler, nur den API-Snapshot
 * (Fahrplan Abschnitt 4.10). Vergangene Tage lassen sich ueber die
 * "Anlagenwerte"-Oberflaeche manuell nachtragen (Abschnitt 4.12/4.17) und
 * erscheinen dann hier wie regulaer erfasste Tage. Tage ganz ohne Sync-Lauf
 * und ohne Nachtrag bleiben eine Luecke.
 *
 * Layout: steht im Dashboard-Grid neben TotalConsumptionChartWidget
 * (sort 10/11), damit Strom- und Thermik-Ansicht fuer "Gesamt" in derselben
 * Zeile stehen. Sort-Werte der sechs neuen Chart-Widgets liegen bewusst eng
 * beieinander (10-15, siehe TotalConsumptionChartWidget-Docblock) statt in
 * 10er-Schritten, damit WeatherWidget (fest bei sort=60, volle Breite)
 * keines der Paare zerreisst.
 *
 * Heading/Description seit Fabians Wunsch (30.10.2026): siehe
 * TotalConsumptionChartWidget-Docblock fuer die Begruendung. Durchschnitt
 * hier auf Basis von thermal_heating_kwh + thermal_dhw_kwh pro Tag
 * (lastSnapshotValue() statt dailyPowerValue(), kein day[]-Array fuer
 * Thermik vorhanden). Gleiches gilt fuer den abgesetzten Punkt des
 * laufenden Tages (30.10.2026).
 */
class TotalThermalChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;
    use ShowsPeriodAverage;

    protected static ?int $sort = 11;

    protected ?string $heading = 'Thermische Energie Gesamt';

    protected int | string | array $columnSpan = 1;

    /**
     * @var array{current: ?float, previous: ?float}|null
     */
    private ?array $averagesCache = null;

    protected function getType(): string
    {
        return 'line';
    }

    public function getHeading(): string | Htmlable | null
    {
        return $this->formatAverageHeading($this->heading ?? '', $this->averages()['current'], 'kWh/Tag');
    }

    public function getDescription(): string | Htmlable | null
    {
        return $this->periodComparisonDescription($this->averages()['current'], $this->averages()['previous'], 'kWh/Tag');
    }

    /**
     * @return array{current: ?float, previous: ?float}
     */
    private function averages(): array
    {
        if ($this->averagesCache !== null) {
            return $this->averagesCache;
        }

        $days = (int) ($this->pageFilters['days'] ?? 30);
        $aggregation = app(ApiLogAggregationService::class);

        $currentFrom = now()->subDays($days)->startOfDay();
        $currentTo = now()->subDay()->startOfDay();
        $previousFrom = $currentFrom->copy()->subDays($days);
        $previousTo = $currentFrom->copy()->subDay();

        return $this->averagesCache = [
            'current' => $this->periodAverage($this->dailyValues($aggregation, $currentFrom, $currentTo)),
            'previous' => $this->periodAverage($this->dailyValues($aggregation, $previousFrom, $previousTo)),
        ];
    }

    /**
     * @return array<int, float|null>
     */
    private function dailyValues(ApiLogAggregationService $aggregation, Carbon $from, Carbon $to): array
    {
        $values = [];
        $cursor = $from->copy();

        while ($cursor->lessThanOrEqualTo($to)) {
            $heating = $aggregation->lastSnapshotValue($cursor, 'thermal_heating_kwh');
            $dhw = $aggregation->lastSnapshotValue($cursor, 'thermal_dhw_kwh');

            $values[] = ($heating === null && $dhw === null) ? null : ($heating ?? 0) + ($dhw ?? 0);
            $cursor = $cursor->copy()->addDay();
        }

        return $values;
    }

    protected function getData(): array
    {
        $days = (int) ($this->pageFilters['days'] ?? 30);
        $aggregation = app(ApiLogAggregationService::class);

        $labels = [];
        $total = [];
        $today = [];

        $requestedStart = now()->subDays($days)->startOfDay();
        $lastDay = now()->subDay()->startOfDay();

        $earliest = $aggregation->earliestReferenceDate([
            'thermal_heating_kwh', 'thermal_dhw_kwh',
        ]);
        $cursor = ($earliest && $earliest->greaterThan($requestedStart) && $earliest->lessThanOrEqualTo($lastDay))
            ? $earliest->copy()
            : $requestedStart;

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $labels[] = $cursor->format('d.m.');

            $heatingValue = $aggregation->lastSnapshotValue($cursor, 'thermal_heating_kwh');
            $dhwValue = $aggregation->lastSnapshotValue($cursor, 'thermal_dhw_kwh');

            $total[] = ($heatingValue === null && $dhwValue === null)
                ? null
                : ($heatingValue ?? 0) + ($dhwValue ?? 0);
            $today[] = null;

            $cursor = $cursor->copy()->addDay();
        }

        // Laufender Tag als abgesetzter Punkt - siehe
        // TotalConsumptionChartWidget-Docblock fuer die Begruendung.
        $todayDate = now()->startOfDay();
        $todayHeating = $aggregation->lastSnapshotValue($todayDate, 'thermal_heating_kwh');
        $todayDhw = $aggregation->lastSnapshotValue($todayDate, 'thermal_dhw_kwh');
        $labels[] = $todayDate->format('d.m.') . ' (heute)';
        $total[] = null;
        $today[] = ($todayHeating === null && $todayDhw === null) ? null : ($todayHeating ?? 0) + ($todayDhw ?? 0);

        return [
            'datasets' => [
                [
                    'label' => 'Gesamt',
                    'data' => $total,
                    'borderColor' => '#ff3e17',
                    'backgroundColor' => '#ff3e1733',
                    'fill' => 'start',
                    'spanGaps' => false,
                ],
                [
                    'label' => 'Gesamt (heute, bisher)',
                    'data' => $today,
                    'borderColor' => '#ff3e17',
                    'backgroundColor' => '#ffffff',
                    'pointBackgroundColor' => '#ffffff',
                    'pointBorderColor' => '#ff3e17',
                    'pointBorderWidth' => 2,
                    'pointRadius' => 6,
                    'showLine' => false,
                    'fill' => false,
                    'spanGaps' => false,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array | RawJs
    {
        return RawJs::make(<<<'JS'
        {
            spanGaps: false,
            scales: {
                y: {
                    ticks: {
                        callback: (value) => value + ' kWh',
                    },
                },
            },
        }
        JS);
    }
}
