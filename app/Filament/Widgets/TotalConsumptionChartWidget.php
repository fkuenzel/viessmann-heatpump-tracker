<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\ShowsPeriodAverage;
use App\Services\ApiLogAggregationService;
use App\Services\MeterCorrectionService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * Taeglicher Gesamt-Stromverbrauch (App vs. Real) ueber einen waehlbaren
 * Zeitraum. Ersetzt seit Fabians Wunsch (30.10.2026) die Gesamt-Linie aus
 * dem fruehren ConsumptionChartWidget, das Heizen/Warmwasser/Gesamt UND
 * App/Real in einem einzigen Chart zeigte (6 Linien) - zu dicht, und der
 * direkte optische Vergleich zur Viessmann-App (ein Chart = eine Metrik)
 * ging dabei verloren (Fabians Review 30.10.2026). Jetzt: ein Chart pro
 * Nutzungsart (Gesamt/Heizen/Warmwasser, siehe HeatingConsumptionChartWidget/
 * DhwConsumptionChartWidget), jeweils nur App vs. Real.
 *
 * Nutzt ApiLogAggregationService::dailyPowerValue() (day[]-Array, findet
 * auch rueckwirkend befuellte Tage, Fahrplan Abschnitt 4.2). Der reale
 * Tageswert ist eine Naeherung: App-Tageswert mal Korrekturfaktor des
 * umschliessenden Ablese-Intervalls (MeterCorrectionService), echte
 * Tagesgenauigkeit gibt es nur auf Intervall-Ebene (MeterDeviationChartWidget/
 * MeterIntervalBreakdownWidget). Tage ausserhalb eines abgeschlossenen
 * Intervalls zeigen eine Luecke statt einer Schaetzung.
 *
 * Der Zeitraum-Filter (7/30/90 Tage) kommt aus dem globalen Dashboard-Filter
 * (App\Filament\Pages\Dashboard::filtersForm()).
 *
 * Layout (Fabians Wunsch 30.10.2026): nach Nutzungsart gruppiert statt nach
 * Metrik-Typ - Strom- und Thermik-Chart derselben Nutzungsart stehen im
 * Dashboard-Grid nebeneinander (sort 10/11, 12/13, 14/15), damit jede Zeile
 * als "so viel Strom rein, so viel Waerme raus" gelesen werden kann. Die
 * sechs neuen Chart-Widgets liegen bewusst eng beieinander (10-15) statt in
 * 10er-Schritten wie zuvor - WeatherWidget steht fest bei sort=60
 * (StatsOverviewWidget, volle Breite), ein 10er-Abstand haette eines der
 * Paare genau dort zerrissen.
 *
 * Laufender Tag (Fabians Wunsch, 30.10.2026): wurde zunaechst komplett
 * ausgelassen (ein Teiltag haette wie ein Einbruch ausgesehen), erscheint
 * jetzt als eigener, abgesetzter Punkt ganz rechts (eigener Dataset ohne
 * Linie, siehe getData()) - so ist auf einen Blick erkennbar, dass "heute"
 * noch laeuft, ohne die Trendlinie der abgeschlossenen Tage zu verfaelschen.
 * Die Durchschnittswerte in Heading/Description (ShowsPeriodAverage)
 * bleiben bewusst unveraendert auf Basis vollstaendiger Tage.
 *
 * Heading/Description seit Fabians Wunsch (30.10.2026): zeigt zusaetzlich
 * den aktuellen Tagesdurchschnitt in der Heading sowie, klein/ausgegraut
 * als Chart-Description, den Durchschnitt des unmittelbar vorangehenden,
 * gleich langen Zeitraums samt prozentualer Veraenderung (siehe
 * App\Filament\Widgets\Concerns\ShowsPeriodAverage fuer die geteilte
 * Formatierungslogik). Eigene, zweite Datenabfrage ueber den Vorzeitraum -
 * bewusst getrennt von getData(), da Filament getHeading()/getDescription()
 * schon vor getCachedData() aufruft (chart-widget.blade.php) und die
 * Vorzeitraum-Werte fuer den Chart selbst nicht gebraucht werden.
 */
class TotalConsumptionChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;
    use ShowsPeriodAverage;

    protected static ?int $sort = 10;

    protected ?string $heading = 'Stromverbrauch Gesamt';

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
            $values[] = $aggregation->dailyPowerValue($cursor, 'power_total_kwh');
            $cursor = $cursor->copy()->addDay();
        }

        return $values;
    }

    protected function getData(): array
    {
        $days = (int) ($this->pageFilters['days'] ?? 30);
        $aggregation = app(ApiLogAggregationService::class);
        $correction = app(MeterCorrectionService::class);
        $intervals = $correction->intervalFactors();

        $labels = [];
        $app = [];
        $real = [];
        $today = [];

        $requestedStart = now()->subDays($days)->startOfDay();
        $lastDay = now()->subDay()->startOfDay();

        $earliest = $aggregation->earliestReferenceDate(['power_total_kwh']);
        $cursor = ($earliest && $earliest->greaterThan($requestedStart) && $earliest->lessThanOrEqualTo($lastDay))
            ? $earliest->copy()
            : $requestedStart;

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $labels[] = $cursor->format('d.m.');

            $value = $aggregation->dailyPowerValue($cursor, 'power_total_kwh');
            $app[] = $value;
            $today[] = null;

            $factor = $correction->factorForDate($cursor, $intervals);
            $real[] = ($factor !== null && $value !== null) ? round($value * $factor, 2) : null;

            $cursor = $cursor->copy()->addDay();
        }

        // Laufender Tag als abgesetzter Punkt (Fabians Wunsch, 30.10.2026) -
        // eigener Dataset statt Verlaengerung der App-Linie, damit der
        // unfertige Tageswert nicht wie ein regulaerer, abgeschlossener
        // Datenpunkt im Verlauf wirkt. showLine:false verhindert eine
        // Verbindungslinie, spanGaps:false (schon global gesetzt) verhindert
        // sie zusaetzlich zum Vortag - der Punkt bleibt isoliert.
        $todayDate = now()->startOfDay();
        $labels[] = $todayDate->format('d.m.') . ' (heute)';
        $app[] = null;
        $real[] = null;
        $today[] = $aggregation->dailyPowerValue($todayDate, 'power_total_kwh');

        return [
            'datasets' => [
                [
                    'label' => 'Gesamt (App)',
                    'data' => $app,
                    'borderColor' => '#43a047',
                    'backgroundColor' => '#43a04733',
                    'fill' => 'start',
                ],
                [
                    'label' => 'Gesamt (Real)',
                    'data' => $real,
                    'borderColor' => '#43a04780',
                    'backgroundColor' => '#43a04780',
                    'borderDash' => [6, 4],
                    'fill' => false,
                    'spanGaps' => false,
                ],
                [
                    'label' => 'Gesamt (heute, bisher)',
                    'data' => $today,
                    'borderColor' => '#43a047',
                    'backgroundColor' => '#ffffff',
                    'pointBackgroundColor' => '#ffffff',
                    'pointBorderColor' => '#43a047',
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
