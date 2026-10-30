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
 * Taeglicher Warmwasser-Stromverbrauch (App vs. Real) ueber einen waehlbaren
 * Zeitraum. Schwester-Widget zu TotalConsumptionChartWidget (siehe dortigen
 * Docblock fuer die Begruendung der Aufteilung, Fabians Review 30.10.2026) -
 * identische Logik, nur auf power_dhw_kwh statt power_total_kwh.
 *
 * Layout: steht im Dashboard-Grid neben DhwThermalChartWidget (sort 14/15),
 * damit Strom- und Thermik-Ansicht fuer "Warmwasser" in derselben Zeile
 * stehen.
 *
 * Heading/Description seit Fabians Wunsch (30.10.2026): siehe
 * TotalConsumptionChartWidget-Docblock fuer die Begruendung. Gleiches gilt
 * fuer den abgesetzten Punkt des laufenden Tages (30.10.2026).
 */
class DhwConsumptionChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;
    use ShowsPeriodAverage;

    protected static ?int $sort = 14;

    protected ?string $heading = 'Stromverbrauch Warmwasser';

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
            $values[] = $aggregation->dailyPowerValue($cursor, 'power_dhw_kwh');
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

        $earliest = $aggregation->earliestReferenceDate(['power_dhw_kwh']);
        $cursor = ($earliest && $earliest->greaterThan($requestedStart) && $earliest->lessThanOrEqualTo($lastDay))
            ? $earliest->copy()
            : $requestedStart;

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $labels[] = $cursor->format('d.m.');

            $value = $aggregation->dailyPowerValue($cursor, 'power_dhw_kwh');
            $app[] = $value;
            $today[] = null;

            $factor = $correction->factorForDate($cursor, $intervals);
            $real[] = ($factor !== null && $value !== null) ? round($value * $factor, 2) : null;

            $cursor = $cursor->copy()->addDay();
        }

        // Laufender Tag als abgesetzter Punkt - siehe
        // TotalConsumptionChartWidget-Docblock fuer die Begruendung.
        $todayDate = now()->startOfDay();
        $labels[] = $todayDate->format('d.m.') . ' (heute)';
        $app[] = null;
        $real[] = null;
        $today[] = $aggregation->dailyPowerValue($todayDate, 'power_dhw_kwh');

        return [
            'datasets' => [
                [
                    'label' => 'Warmwasser (App)',
                    'data' => $app,
                    'borderColor' => '#81c784',
                    'backgroundColor' => '#81c78433',
                    'fill' => 'start',
                ],
                [
                    'label' => 'Warmwasser (Real)',
                    'data' => $real,
                    'borderColor' => '#81c78480',
                    'backgroundColor' => '#81c78480',
                    'borderDash' => [6, 4],
                    'fill' => false,
                    'spanGaps' => false,
                ],
                [
                    'label' => 'Warmwasser (heute, bisher)',
                    'data' => $today,
                    'borderColor' => '#81c784',
                    'backgroundColor' => '#ffffff',
                    'pointBackgroundColor' => '#ffffff',
                    'pointBorderColor' => '#81c784',
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
