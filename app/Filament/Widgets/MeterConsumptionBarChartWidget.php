<?php

namespace App\Filament\Widgets;

use App\Models\MeterReading;
use App\Services\MeterCorrectionService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Vergleicht pro Ablese-Intervall den realen Zaehler-Verbrauch (Differenz
 * zwischen zwei MeterReading-Eintraegen) mit dem ueber die Viessmann-API
 * ermittelten Verbrauch im selben Zeitraum (MeterCorrectionService, bisher
 * nur ueber das Hilfs-Command report:meter-interval abrufbar, siehe
 * Fahrplan Phase 4).
 *
 * Ersetzt seit Fabians Wunsch (30.10.2026) das fruehere
 * MeterDeviationChartWidget, das zusaetzlich die Abweichung in % auf einer
 * zweiten y-Achse zeigte - zwei Achsen gleichzeitig (kWh links, % rechts)
 * waren schwer lesbar, siehe Schwester-Widget
 * MeterDeviationPercentChartWidget fuer die Abweichung als eigenes Chart
 * mit eigener Achse.
 *
 * Zaehlerstaende fallen nur unregelmaessig an (keine Tagesgranularitaet wie
 * bei den anderen Chart-Widgets), daher ein Balkendiagramm pro Intervall
 * statt einer Zeitreihe.
 *
 * Achtung bei der Lesart (Fabians Review 29.10.2026): die Balken sind gleich
 * breit dargestellt, obwohl die Intervalle unterschiedlich lang sind (hier
 * 1 bis 4 Tage) - ein kurzes und ein langes Intervall sehen dadurch optisch
 * gleich "schwer" aus. Da eine echte proportionale Zeitachse bei
 * kategorialen Chart.js-Balken nicht sinnvoll umsetzbar ist, wird die
 * Intervall-Dauer stattdessen im Tooltip mit angezeigt (afterTitle), damit
 * man die Werte nicht ohne diesen Kontext vergleicht.
 *
 * Farbgebung: beide Balken bilden Stromverbrauch ab (Zaehler vs.
 * App-Meldung) und bekommen daher die gruene Farbfamilie der App (siehe
 * ConsumptionChartWidget), unterschieden ueber Helligkeit.
 *
 * Durchschnitt (Fabians Wunsch, 30.10.2026): dieses Widget hat keinen
 * Zeitraum-Filter wie die taeglichen Charts (es zeigt alle bisherigen
 * Ablese-Intervalle), daher kein "Vorzeitraum"-Vergleich wie bei
 * TotalConsumptionChartWidget & Co. Stattdessen in der Beschreibung ein
 * ueber alle Intervalle gewichteter Tagesdurchschnitt (Summe Verbrauch /
 * Summe Intervall-Tage) fuer Zaehler und App - eine einfache Mittelung der
 * Intervallwerte waere durch die unterschiedlich langen Intervalle
 * verzerrt (siehe Hinweis zur Balkenbreite oben).
 */
class MeterConsumptionBarChartWidget extends ChartWidget
{
    protected static ?int $sort = 18;

    protected ?string $heading = 'Verbrauch je Ablese-Intervall';

    // Halbe Breite (Dashboard-Grid hat 2 Spalten) - steht neben
    // MeterDeviationPercentChartWidget in derselben Zeile.
    protected int | string | array $columnSpan = 1;

    /**
     * Intervall-Dauer in Tagen, parallel zu den Labels/Datasets befuellt
     * (getData() laeuft vor getOptions(), siehe Chart.js-Widget-Lifecycle).
     * Wird in getOptions() per json_encode in den Tooltip-Callback
     * eingebettet, um die Dauer dort anzuzeigen (Fabians Review 29.10.2026).
     *
     * @var array<int, float>
     */
    protected array $intervalDurations = [];

    /**
     * @var array{meter: ?float, app: ?float}|null
     */
    private ?array $averagesCache = null;

    public function getMaxHeight(): ?string
    {
        return '300px';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    public function getDescription(): string | Htmlable | null
    {
        $averages = $this->averages();

        if ($averages['meter'] === null && $averages['app'] === null) {
            return null;
        }

        $meterFormatted = $averages['meter'] !== null
            ? number_format($averages['meter'], 2, ',', '.') . ' kWh/Tag'
            : '–';
        $appFormatted = $averages['app'] !== null
            ? number_format($averages['app'], 2, ',', '.') . ' kWh/Tag'
            : '–';

        return 'Ø Zähler: ' . $meterFormatted . ' · Ø App: ' . $appFormatted;
    }

    /**
     * Gewichteter Tagesdurchschnitt ueber alle Ablese-Intervalle - siehe
     * Klassen-Docblock fuer die Begruendung der Gewichtung.
     *
     * @return array{meter: ?float, app: ?float}
     */
    private function averages(): array
    {
        if ($this->averagesCache !== null) {
            return $this->averagesCache;
        }

        $correction = app(MeterCorrectionService::class);
        $readings = MeterReading::query()->orderBy('timestamp')->get();

        $meterTotal = 0.0;
        $appTotal = 0.0;
        $daysTotal = 0.0;

        foreach ($readings as $index => $reading) {
            if ($index === 0) {
                continue;
            }

            $previous = $readings[$index - 1];
            $result = $correction->calculate($previous, $reading);
            $days = $previous->timestamp->diffInSeconds($reading->timestamp) / 86400;

            $meterTotal += $result['meter_kwh'];
            $appTotal += $result['api_kwh'] ?? 0.0;
            $daysTotal += $days;
        }

        return $this->averagesCache = [
            'meter' => $daysTotal > 0 ? $meterTotal / $daysTotal : null,
            'app' => $daysTotal > 0 ? $appTotal / $daysTotal : null,
        ];
    }

    protected function getData(): array
    {
        $correction = app(MeterCorrectionService::class);

        $readings = MeterReading::query()->orderBy('timestamp')->get();

        $labels = [];
        $meterValues = [];
        $apiValues = [];
        $this->intervalDurations = [];

        foreach ($readings as $index => $reading) {
            if ($index === 0) {
                continue;
            }

            $previous = $readings[$index - 1];
            $result = $correction->calculate($previous, $reading);

            $labels[] = $previous->timestamp->format('d.m.') . ' - ' . $reading->timestamp->format('d.m.');
            $meterValues[] = $result['meter_kwh'];
            $apiValues[] = $result['api_kwh'];
            $this->intervalDurations[] = round($previous->timestamp->diffInSeconds($reading->timestamp) / 86400, 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Verbrauch Zähler (kWh)',
                    'data' => $meterValues,
                    'backgroundColor' => '#43a047',
                ],
                [
                    'label' => 'Verbrauch App (kWh)',
                    'data' => $apiValues,
                    'backgroundColor' => '#81c784',
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
                        callback: (value) => value + ' kWh',
                    },
                },
            },
        }
        JS);
    }
}
