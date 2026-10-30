<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ShowsPeriodAverage;
use App\Services\ApiLogAggregationService;
use App\Services\CopCalculationService;
use App\Services\MeterCorrectionService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * Taeglicher COP (Thermik/Strom) ueber einen waehlbaren Zeitraum, automatisch
 * aus Tageswerten berechnet (CopCalculationService::forDate() - braucht
 * lediglich Strom- UND Thermik-Wert fuer denselben Tag, egal ob per Sync
 * oder manuellem Nachtrag entstanden, siehe Fahrplan Abschnitt 4.12/4.17).
 * Tage ohne beide Werte erscheinen im Chart als Luecke (Chart.js
 * spanGaps: false).
 *
 * Zeigt seit Fabians Wunsch (29.10.2026, Abschnitt 4.22) zusaetzlich eine
 * "Real"-Linie (Zaehler-Basis statt App/API) in derselben Farbe bei 50%
 * Deckkraft UND gestrichelt (Fabians Review 29.10.2026 - Unterscheidung
 * allein ueber Deckkraft war bei dichten Kurven zu schwach): realer COP =
 * App-COP / Korrekturfaktor des umschliessenden Ablese-Intervalls
 * (MeterCorrectionService), da der App-COP den App-Stromwert im Nenner
 * nutzt und der Korrekturfaktor meter_kwh/api_kwh ist. Naeherung wie bei
 * ConsumptionChartWidget - Tage ausserhalb eines abgeschlossenen Intervalls
 * zeigen eine Luecke statt einer Schaetzung.
 *
 * Der Zeitraum-Filter (7/30/90 Tage) kommt seit Fabians Review (29.10.2026)
 * aus dem globalen Dashboard-Filter (App\Filament\Pages\Dashboard::
 * filtersForm()) statt aus einem eigenen Widget-Dropdown.
 *
 * Farbgebung seit Fabians Wunsch (30.10.2026): COP ist ein Verhaeltnis aus
 * Thermik und Strom und gehoert damit zu keiner der beiden App-Farbfamilien
 * (Gruen = Strom, Rot-Orange = Thermik, siehe ConsumptionChartWidget/
 * ThermalProductionChartWidget) - bewusst ein eigener, neutraler Blauton
 * statt einer der beiden Energiefarben, damit COP nicht faelschlich als
 * "gehoert zu Strom" oder "gehoert zu Thermik" gelesen wird.
 *
 * Heading/Description seit Fabians Wunsch (30.10.2026): siehe
 * TotalConsumptionChartWidget-Docblock fuer die Begruendung. Kein
 * Einheiten-Suffix (COP ist dimensionslos, kein "kWh/Tag"). Gleiches gilt
 * fuer den abgesetzten Punkt des laufenden Tages (30.10.2026).
 */
class CopChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;
    use ShowsPeriodAverage;

    protected static ?int $sort = 16;

    protected ?string $heading = 'COP (Tageswert)';

    // Halbe Breite (Dashboard-Grid hat 2 Spalten, Filament\Pages\Dashboard::getColumns())
    // - zwei Charts stehen dadurch nebeneinander, auf schmalen Bildschirmen
    // stapelt Filaments responsives Grid automatisch (Fabian 29.10.2026).
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
        return $this->formatAverageHeading($this->heading ?? '', $this->averages()['current'], '');
    }

    public function getDescription(): string | Htmlable | null
    {
        return $this->periodComparisonDescription($this->averages()['current'], $this->averages()['previous'], '');
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
        $cop = app(CopCalculationService::class);

        $currentFrom = now()->subDays($days)->startOfDay();
        $currentTo = now()->subDay()->startOfDay();
        $previousFrom = $currentFrom->copy()->subDays($days);
        $previousTo = $currentFrom->copy()->subDay();

        return $this->averagesCache = [
            'current' => $this->periodAverage($this->dailyValues($cop, $currentFrom, $currentTo)),
            'previous' => $this->periodAverage($this->dailyValues($cop, $previousFrom, $previousTo)),
        ];
    }

    /**
     * @return array<int, float|null>
     */
    private function dailyValues(CopCalculationService $cop, Carbon $from, Carbon $to): array
    {
        $values = [];
        $cursor = $from->copy();

        while ($cursor->lessThanOrEqualTo($to)) {
            $values[] = $cop->forDate($cursor);
            $cursor = $cursor->copy()->addDay();
        }

        return $values;
    }

    protected function getData(): array
    {
        $days = (int) ($this->pageFilters['days'] ?? 30);
        $cop = app(CopCalculationService::class);
        $aggregation = app(ApiLogAggregationService::class);
        $correction = app(MeterCorrectionService::class);
        $intervals = $correction->intervalFactors();

        $labels = [];
        $values = [];
        $realValues = [];
        $today = [];

        $requestedStart = now()->subDays($days)->startOfDay();
        $lastDay = now()->subDay()->startOfDay();

        // Zeitraum an tatsaechlich vorhandene Thermik-Daten anpassen, da COP
        // ohne Thermik-Snapshot nicht berechenbar ist (Abschnitt 4.10) - sonst
        // fast nur Leerflaeche vor einem einzelnen Datenpunkt (Fabian
        // 29.10.2026 - Dashboard-Review).
        $earliest = $aggregation->earliestReferenceDate([
            'thermal_heating_kwh', 'thermal_dhw_kwh',
        ]);
        $cursor = ($earliest && $earliest->greaterThan($requestedStart) && $earliest->lessThanOrEqualTo($lastDay))
            ? $earliest->copy()
            : $requestedStart;

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $labels[] = $cursor->format('d.m.');

            $value = $cop->forDate($cursor);
            $values[] = $value;
            $today[] = null;

            $factor = $correction->factorForDate($cursor, $intervals);
            $realValues[] = ($factor !== null && $value !== null && $factor > 0) ? round($value / $factor, 3) : null;

            $cursor = $cursor->copy()->addDay();
        }

        // Laufender Tag als abgesetzter Punkt - siehe
        // TotalConsumptionChartWidget-Docblock fuer die Begruendung. COP
        // (heute) ist ein vorlaeufiger Wert aus den bisherigen Teilwerten
        // des Tages - kein Real-Pendant, da MeterCorrectionService nur
        // abgeschlossene Ablese-Intervalle korrigiert.
        $todayDate = now()->startOfDay();
        $labels[] = $todayDate->format('d.m.') . ' (heute)';
        $values[] = null;
        $realValues[] = null;
        $today[] = $cop->forDate($todayDate);

        return [
            'datasets' => [
                [
                    'label' => 'COP (App)',
                    'data' => $values,
                    'borderColor' => '#4a6fa5',
                    'backgroundColor' => '#4a6fa5',
                    'fill' => false,
                    'spanGaps' => false,
                ],
                [
                    'label' => 'COP (Real)',
                    'data' => $realValues,
                    'borderColor' => '#4a6fa580',
                    'backgroundColor' => '#4a6fa580',
                    'borderDash' => [6, 4],
                    'fill' => false,
                    'spanGaps' => false,
                ],
                [
                    'label' => 'COP (heute, bisher)',
                    'data' => $today,
                    'borderColor' => '#4a6fa5',
                    'backgroundColor' => '#ffffff',
                    'pointBackgroundColor' => '#ffffff',
                    'pointBorderColor' => '#4a6fa5',
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
            plugins: {
                tooltip: {
                    callbacks: {
                        afterLabel: (context) => {
                            const label = context.dataset.label ?? '';
                            let pairLabel = null;

                            if (label.includes('(App)')) {
                                pairLabel = label.replace('(App)', '(Real)');
                            } else if (label.includes('(Real)')) {
                                pairLabel = label.replace('(Real)', '(App)');
                            }

                            if (! pairLabel) {
                                return '';
                            }

                            const pairDataset = context.chart.data.datasets.find((dataset) => dataset.label === pairLabel);
                            const pairValue = pairDataset ? pairDataset.data[context.dataIndex] : null;

                            if (pairValue === null || pairValue === undefined) {
                                return '';
                            }

                            return pairLabel + ': ' + pairValue;
                        },
                    },
                },
            },
        }
        JS);
    }
}
