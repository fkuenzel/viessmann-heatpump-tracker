<?php

namespace App\Filament\Widgets;

use App\Services\ApiLogAggregationService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Anteil Heizen/Warmwasser am App-Stromverbrauch im gewaehlten Zeitraum, als
 * Donut-Chart analog zur Viessmann-App ("Stromverbrauch pro Nutzung",
 * Fabians Screenshot 30.10.2026). Nutzt denselben Zeitraum-Filter wie
 * ConsumptionChartWidget/ThermalProductionChartWidget/CopChartWidget
 * (App\Filament\Pages\Dashboard::filtersForm()) und summiert
 * ApiLogAggregationService::dailyPowerValue() ueber den Zeitraum - bewusst
 * keine neue Aggregationslogik, sondern dieselbe Tagesbasis wie
 * ConsumptionChartWidget, damit beide Widgets bei gleichem Zeitraum
 * konsistente Zahlen zeigen.
 *
 * Kuehlen wird bewusst nicht angezeigt - api_log hat keine
 * power_cooling_kwh-Spalte (database/migrations/
 * 2026_10_28_100002_create_api_log_table.php kennt nur power_heating_kwh/
 * power_dhw_kwh/power_total_kwh), das Tracking erfasst aktuell also nur
 * Heizen/Warmwasser. In der Viessmann-App selbst steht Kuehlen bei Fabians
 * Anlage ohnehin durchgehend bei 0%.
 *
 * Farbgebung: Heizen in der Viessmann-Highlight-Farbe (--vic-color__highlight:
 * #cc2c08, wie Heizen im Thermik-Chart). Warmwasser bewusst in Blau (#3b82f6)
 * statt in der aufgehellten Rot-Orange-Ableitung von zuvor - Fabians Wunsch
 * (30.10.2026), da Blau als Farbe fuer Warmwasser intuitiver ist und sich im
 * Donut klarer von Heizen abhebt als zwei Toene derselben Farbfamilie.
 *
 * Vorzeitraum-Vergleich seit Fabians Wunsch (30.10.2026, analog zu den
 * Liniencharts, siehe TotalConsumptionChartWidget-Docblock): bei einem
 * Donut ist nicht der Gesamtwert (der haengt am Zeitraum, nicht an der
 * Nutzung) die sinnvolle Vergleichsgroesse, sondern der Heizen-Anteil in %,
 * also wie sich die Verteilung zwischen Heizen und Warmwasser gegenueber
 * dem Vorzeitraum verschoben hat (z. B. saisonal). Die Beschreibung zeigt
 * daher "Vorzeitraum: Heizen X %" plus die Verschiebung in Prozentpunkten.
 * Kein abgesetzter "heute"-Punkt - der Donut ist eine Zeitraum-Summe, kein
 * Tagesverlauf, "heute" ist darin bereits anteilig enthalten.
 */
class HeatingDhwSplitChartWidget extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 17;

    protected ?string $heading = 'Stromverbrauch nach Nutzung';

    // Halbe Breite (Dashboard-Grid hat 2 Spalten, Filament\Pages\Dashboard::getColumns()).
    protected int | string | array $columnSpan = 1;

    /**
     * @var array{current: ?float, previous: ?float}|null
     */
    private ?array $heatingShareCache = null;

    protected function getType(): string
    {
        return 'doughnut';
    }

    public function getDescription(): string | Htmlable | null
    {
        $shares = $this->heatingShares();

        if ($shares['previous'] === null) {
            return null;
        }

        $previousFormatted = number_format($shares['previous'], 1, ',', '.') . ' %';

        if ($shares['current'] === null) {
            return 'Vorzeitraum: Heizen Ø ' . $previousFormatted;
        }

        $deltaPoints = $shares['current'] - $shares['previous'];
        $sign = $deltaPoints >= 0 ? '+' : '';

        return 'Vorzeitraum: Heizen ' . $previousFormatted
            . ' (' . $sign . number_format($deltaPoints, 1, ',', '.') . ' Prozentpunkte)';
    }

    /**
     * Heizen-Anteil (%) am Stromverbrauch fuer den aktuellen und den
     * gleich langen Vorzeitraum - siehe Klassen-Docblock fuer die
     * Begruendung, warum hier der Anteil statt eines Absolutwerts
     * verglichen wird.
     *
     * @return array{current: ?float, previous: ?float}
     */
    private function heatingShares(): array
    {
        if ($this->heatingShareCache !== null) {
            return $this->heatingShareCache;
        }

        $days = (int) ($this->pageFilters['days'] ?? 30);
        $aggregation = app(ApiLogAggregationService::class);

        $currentFrom = now()->subDays($days)->startOfDay();
        $currentTo = now()->subDay()->startOfDay();
        $previousFrom = $currentFrom->copy()->subDays($days);
        $previousTo = $currentFrom->copy()->subDay();

        return $this->heatingShareCache = [
            'current' => $this->heatingShareForRange($aggregation, $currentFrom, $currentTo),
            'previous' => $this->heatingShareForRange($aggregation, $previousFrom, $previousTo),
        ];
    }

    private function heatingShareForRange(ApiLogAggregationService $aggregation, \Illuminate\Support\Carbon $from, \Illuminate\Support\Carbon $to): ?float
    {
        $heatingSum = 0.0;
        $dhwSum = 0.0;
        $hasValue = false;

        $cursor = $from->copy();

        while ($cursor->lessThanOrEqualTo($to)) {
            $heatingValue = $aggregation->dailyPowerValue($cursor, 'power_heating_kwh');
            $dhwValue = $aggregation->dailyPowerValue($cursor, 'power_dhw_kwh');

            if ($heatingValue !== null || $dhwValue !== null) {
                $heatingSum += $heatingValue ?? 0.0;
                $dhwSum += $dhwValue ?? 0.0;
                $hasValue = true;
            }

            $cursor = $cursor->copy()->addDay();
        }

        $total = $heatingSum + $dhwSum;

        if (! $hasValue || $total <= 0.0) {
            return null;
        }

        return ($heatingSum / $total) * 100;
    }

    protected function getData(): array
    {
        $days = (int) ($this->pageFilters['days'] ?? 30);
        $aggregation = app(ApiLogAggregationService::class);

        $requestedStart = now()->subDays($days)->startOfDay();
        $lastDay = now()->subDay()->startOfDay();

        // Zeitraum an tatsaechlich vorhandene Daten anpassen, identisch zur
        // Logik in ConsumptionChartWidget (Abschnitt 4.2) - sonst waere die
        // Summe bei kurzer Datenhistorie kuenstlich klein.
        $earliest = $aggregation->earliestReferenceDate([
            'power_total_kwh', 'power_heating_kwh', 'power_dhw_kwh',
        ]);
        $cursor = ($earliest && $earliest->greaterThan($requestedStart) && $earliest->lessThanOrEqualTo($lastDay))
            ? $earliest->copy()
            : $requestedStart;

        $heatingSum = 0.0;
        $dhwSum = 0.0;
        $hasValue = false;

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $heatingValue = $aggregation->dailyPowerValue($cursor, 'power_heating_kwh');
            $dhwValue = $aggregation->dailyPowerValue($cursor, 'power_dhw_kwh');

            if ($heatingValue !== null || $dhwValue !== null) {
                $heatingSum += $heatingValue ?? 0.0;
                $dhwSum += $dhwValue ?? 0.0;
                $hasValue = true;
            }

            $cursor = $cursor->copy()->addDay();
        }

        return [
            'datasets' => [
                [
                    'data' => $hasValue ? [round($heatingSum, 2), round($dhwSum, 2)] : [],
                    'backgroundColor' => ['#cc2c08', '#3b82f6'],
                ],
            ],
            'labels' => ['Heizen', 'Warmwasser'],
        ];
    }

    protected function getOptions(): array | RawJs
    {
        return RawJs::make(<<<'JS'
        {
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (context) => {
                            const value = context.parsed ?? 0;
                            const total = context.dataset.data.reduce((sum, v) => sum + v, 0);
                            const percent = total > 0 ? ((value / total) * 100).toFixed(1) : '0.0';

                            return context.label + ': ' + value.toLocaleString('de-DE') + ' kWh (' + percent + ' %)';
                        },
                    },
                },
            },
        }
        JS);
    }
}
