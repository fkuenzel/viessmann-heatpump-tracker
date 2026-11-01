<?php

namespace App\Filament\Widgets;

use App\Models\MeterReading;
use App\Services\MeterCorrectionService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Zeigt pro Ablese-Intervall, wie stark der ueber die Viessmann-API
 * gemeldete Verbrauch vom realen Zaehler-Verbrauch abweicht, in Prozent
 * (MeterCorrectionService). Schwester-Widget zu
 * MeterConsumptionBarChartWidget (siehe dortigen Docblock fuer die
 * Begruendung der Aufteilung, Fabians Review 30.10.2026) - identische
 * Datengrundlage, aber als eigenes Chart mit eigener %-Achse statt einer
 * zweiten y-Achse im selben Chart.
 *
 * Abweichung = (App - Zaehler) / Zaehler * 100, exakt wie in Fabians Excel
 * (bewusst nicht result['difference_percent'] aus Phase 4, das ist die
 * umgekehrte Perspektive meter/api).
 *
 * Gleiche Einschraenkung bei der Lesart wie beim Schwester-Widget: die
 * Balken/Punkte sind gleich breit dargestellt, obwohl die Intervalle
 * unterschiedlich lang sind - die Dauer steht deshalb im Tooltip
 * (afterTitle).
 *
 * Farbe: Rot-Orange-Highlight (--vic-color__highlight: #cc2c08) wie die
 * bisherige Abweichungs-Linie - gehoert zu keiner der beiden
 * Energie-Farbfamilien (Gruen = Strom, Rot-Orange-Marke = Thermik), hier
 * aber bewusst dieselbe Farbe wie vorher beibehalten, da sie bereits als
 * "Abweichung/Warnsignal" etabliert ist.
 *
 * Durchschnitt (Fabians Wunsch, 30.10.2026): einfacher arithmetischer
 * Mittelwert der Abweichungs-Prozentwerte ueber alle Intervalle (anders als
 * beim Verbrauchs-Chart hier bewusst NICHT nach Intervall-Dauer gewichtet,
 * da die Abweichung selbst schon ein Verhaeltniswert ist und eine
 * Gewichtung nach Dauer hier keine zusaetzliche Aussagekraft bringt).
 */
class MeterDeviationPercentChartWidget extends ChartWidget
{
    protected static ?int $sort = 19;

    protected ?string $heading = 'Abweichung Zähler ↔ App';

    // Halbe Breite (Dashboard-Grid hat 2 Spalten) - steht neben
    // MeterConsumptionBarChartWidget in derselben Zeile.
    protected int | string | array $columnSpan = 1;

    /**
     * @var array<int, float>
     */
    protected array $intervalDurations = [];

    private ?float $averageCache = null;

    private bool $averageComputed = false;

    public function getMaxHeight(): ?string
    {
        return '300px';
    }

    protected function getType(): string
    {
        return 'line';
    }

    public function getDescription(): string | Htmlable | null
    {
        $average = $this->average();

        if ($average === null) {
            return null;
        }

        $sign = $average >= 0 ? '+' : '';

        return 'Ø Abweichung: ' . $sign . number_format($average, 1, ',', '.') . ' %';
    }

    private function average(): ?float
    {
        if ($this->averageComputed) {
            return $this->averageCache;
        }

        $this->averageComputed = true;

        $correction = app(MeterCorrectionService::class);
        $readings = MeterReading::query()->orderBy('timestamp')->get();

        $values = [];

        foreach ($readings as $index => $reading) {
            if ($index === 0) {
                continue;
            }

            $previous = $readings[$index - 1];
            $result = $correction->calculate($previous, $reading);

            if ($result['api_kwh'] !== null && $result['meter_kwh'] > 0) {
                $values[] = (($result['api_kwh'] - $result['meter_kwh']) / $result['meter_kwh']) * 100;
            }
        }

        return $this->averageCache = $values === [] ? null : array_sum($values) / count($values);
    }

    protected function getData(): array
    {
        $correction = app(MeterCorrectionService::class);

        $readings = MeterReading::query()->orderBy('timestamp')->get();

        $labels = [];
        $deviationValues = [];
        $this->intervalDurations = [];

        foreach ($readings as $index => $reading) {
            if ($index === 0) {
                continue;
            }

            $previous = $readings[$index - 1];
            $result = $correction->calculate($previous, $reading);

            $labels[] = $previous->timestamp->format('d.m.') . ' - ' . $reading->timestamp->format('d.m.');
            $this->intervalDurations[] = round($previous->timestamp->diffInSeconds($reading->timestamp) / 86400, 2);

            $deviationValues[] = ($result['api_kwh'] !== null && $result['meter_kwh'] > 0)
                ? round((($result['api_kwh'] - $result['meter_kwh']) / $result['meter_kwh']) * 100, 2)
                : null;
        }

        return [
            'datasets' => [
                [
                    'label' => 'Abweichung Zähler ↔ App',
                    'data' => $deviationValues,
                    'borderColor' => '#cc2c08',
                    'backgroundColor' => '#cc2c0833',
                    'fill' => 'start',
                    'tension' => 0.2,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array | RawJs
    {
        $durationsJson = json_encode($this->intervalDurations);

        return RawJs::make(<<<JS
        {
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        afterTitle: (items) => {
                            const durations = {$durationsJson};
                            const index = items[0]?.dataIndex;
                            const duration = durations[index];

                            return (duration === undefined) ? '' : 'Dauer: ' + duration.toLocaleString('de-DE') + ' Tage';
                        },
                    },
                },
            },
            scales: {
                y: {
                    ticks: {
                        callback: (value) => value + ' %',
                    },
                },
            },
        }
        JS);
    }
}
