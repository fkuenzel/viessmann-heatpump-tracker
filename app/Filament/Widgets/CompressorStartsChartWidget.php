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
 * Taegliche Kompressorstarts (Delta aus dem kumulativen Zaehlerstand
 * compressor_starts seit Inbetriebnahme) ueber einen waehlbaren Zeitraum.
 * Fabian vermisste die Kompressor-Statistik auf dem Dashboard (30.10.2026)
 * - sie wurde bisher nur in der "Anlagenwerte"-Tabelle als Rohwert
 * erfasst/korrigiert, aber nirgendwo visualisiert.
 *
 * compressor_starts ist wie thermal_heating_kwh ein Snapshot-Wert (nur per
 * automatischem Sync fuer den laufenden Tag, per manuellem Nachtrag auch
 * fuer vergangene Tage, siehe Fahrplan Abschnitt 4.17) und ein kumulativer
 * Zaehler seit Inbetriebnahme, kein Tageswert - die Tages-Starts ergeben
 * sich daher als Differenz zweier aufeinanderfolgender Tageswerte
 * (ApiLogAggregationService::lastSnapshotValue()). Fehlt einer der beiden
 * Werte, bleibt der Tag eine Luecke statt einer falschen Schaetzung.
 *
 * Schwester-Widget zu CompressorHoursChartWidget (identische Logik, anderes
 * Feld) - beide stehen im Dashboard-Grid nebeneinander. Farbe bewusst in
 * Lila statt Gruen/Rot-Orange, da Kompressorstatistik weder Strom- noch
 * Thermik-Daten sind, sondern Betriebs-/Verschleisswerte der Anlage;
 * Starts in der dunkleren, Betriebsstunden in der helleren Lila-Abstufung
 * (gleiches Helligkeits-Prinzip wie bei den Energiefarben).
 *
 * Heading/Description und abgesetzter "heute"-Punkt analog zu
 * TotalConsumptionChartWidget (siehe dortigen Docblock fuer die
 * Begruendung).
 */
class CompressorStartsChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;
    use ShowsPeriodAverage;

    protected static ?int $sort = 21;

    protected ?string $heading = 'Kompressorstarts';

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
        return $this->formatAverageHeading($this->heading ?? '', $this->averages()['current'], 'Starts/Tag');
    }

    public function getDescription(): string | Htmlable | null
    {
        return $this->periodComparisonDescription($this->averages()['current'], $this->averages()['previous'], 'Starts/Tag');
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
        $current = $aggregation->lastSnapshotValue($date, 'compressor_starts');
        $previous = $aggregation->lastSnapshotValue($date->copy()->subDay(), 'compressor_starts');

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
        $starts = [];
        $today = [];

        $requestedStart = now()->subDays($days)->startOfDay();
        $lastDay = now()->subDay()->startOfDay();

        $earliest = $aggregation->earliestReferenceDate(['compressor_starts']);
        $cursor = ($earliest && $earliest->greaterThan($requestedStart) && $earliest->lessThanOrEqualTo($lastDay))
            ? $earliest->copy()
            : $requestedStart;

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $labels[] = $cursor->format('d.m.');

            $starts[] = $this->deltaForDate($aggregation, $cursor);
            $today[] = null;

            $cursor = $cursor->copy()->addDay();
        }

        // Laufender Tag als abgesetzter Punkt - siehe
        // TotalConsumptionChartWidget-Docblock fuer die Begruendung.
        $todayDate = now()->startOfDay();
        $labels[] = $todayDate->format('d.m.') . ' (heute)';
        $starts[] = null;
        $today[] = $this->deltaForDate($aggregation, $todayDate);

        return [
            'datasets' => [
                [
                    'label' => 'Kompressorstarts',
                    'data' => $starts,
                    'backgroundColor' => '#6a1b9a',
                ],
                [
                    'label' => 'Kompressorstarts (heute, bisher)',
                    'data' => $today,
                    'borderColor' => '#6a1b9a',
                    'backgroundColor' => '#ffffff',
                    'pointBackgroundColor' => '#ffffff',
                    'pointBorderColor' => '#6a1b9a',
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
                        callback: (value) => value + ' Starts',
                    },
                },
            },
        }
        JS);
    }
}
