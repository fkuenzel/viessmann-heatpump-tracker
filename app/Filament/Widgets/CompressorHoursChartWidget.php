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
 * Taegliche Kompressor-Betriebsstunden (Delta aus dem kumulativen
 * Zaehlerstand compressor_hours seit Inbetriebnahme) ueber einen
 * waehlbaren Zeitraum. Schwester-Widget zu CompressorStartsChartWidget
 * (siehe dortigen Docblock fuer die Begruendung, Fabians Wunsch
 * 30.10.2026) - identische Logik, nur auf compressor_hours statt
 * compressor_starts.
 *
 * Layout: steht im Dashboard-Grid neben CompressorStartsChartWidget
 * (sort 21/22).
 *
 * Heading/Description und abgesetzter "heute"-Punkt analog zu
 * TotalConsumptionChartWidget (siehe dortigen Docblock fuer die
 * Begruendung).
 */
class CompressorHoursChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;
    use ShowsPeriodAverage;

    protected static ?int $sort = 22;

    protected ?string $heading = 'Kompressor-Betriebsstunden';

    protected int | string | array $columnSpan = 1;

    /**
     * @var array{current: ?float, previous: ?float}|null
     */
    private ?array $averagesCache = null;

    protected function getType(): string
    {
        return 'bar';
    }

    public function getHeading(): string | Htmlable | null
    {
        return $this->formatAverageHeading($this->heading ?? '', $this->averages()['current'], 'Std./Tag');
    }

    public function getDescription(): string | Htmlable | null
    {
        return $this->periodComparisonDescription($this->averages()['current'], $this->averages()['previous'], 'Std./Tag');
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
            $values[] = $this->deltaForDate($aggregation, $cursor);
            $cursor = $cursor->copy()->addDay();
        }

        return $values;
    }

    private function deltaForDate(ApiLogAggregationService $aggregation, Carbon $date): ?float
    {
        $current = $aggregation->lastSnapshotValue($date, 'compressor_hours');
        $previous = $aggregation->lastSnapshotValue($date->copy()->subDay(), 'compressor_hours');

        if ($current === null || $previous === null) {
            return null;
        }

        return $current - $previous;
    }

    protected function getData(): array
    {
        $days = (int) ($this->pageFilters['days'] ?? 30);
        $aggregation = app(ApiLogAggregationService::class);

        $labels = [];
        $hours = [];
        $today = [];

        $requestedStart = now()->subDays($days)->startOfDay();
        $lastDay = now()->subDay()->startOfDay();

        $earliest = $aggregation->earliestReferenceDate(['compressor_hours']);
        $cursor = ($earliest && $earliest->greaterThan($requestedStart) && $earliest->lessThanOrEqualTo($lastDay))
            ? $earliest->copy()
            : $requestedStart;

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $labels[] = $cursor->format('d.m.');

            $hours[] = $this->deltaForDate($aggregation, $cursor);
            $today[] = null;

            $cursor = $cursor->copy()->addDay();
        }

        // Laufender Tag als abgesetzter Punkt - siehe
        // TotalConsumptionChartWidget-Docblock fuer die Begruendung.
        $todayDate = now()->startOfDay();
        $labels[] = $todayDate->format('d.m.') . ' (heute)';
        $hours[] = null;
        $today[] = $this->deltaForDate($aggregation, $todayDate);

        return [
            'datasets' => [
                [
                    'label' => 'Betriebsstunden',
                    'data' => $hours,
                    'backgroundColor' => '#ab47bc',
                ],
                [
                    'label' => 'Betriebsstunden (heute, bisher)',
                    'data' => $today,
                    'borderColor' => '#ab47bc',
                    'backgroundColor' => '#ffffff',
                    'pointBackgroundColor' => '#ffffff',
                    'pointBorderColor' => '#ab47bc',
                    'pointBorderWidth' => 2,
                    'pointRadius' => 6,
                    'showLine' => false,
                    'type' => 'line',
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
            scales: {
                y: {
                    ticks: {
                        callback: (value) => value + ' Std.',
                    },
                },
            },
        }
        JS);
    }
}
