<?php

namespace App\Filament\Widgets;

use App\Models\MeterReading;
use App\Services\MeterCorrectionService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Vergleicht pro Ablese-Intervall den realen Zaehler-Verbrauch (Differenz
 * zwischen zwei MeterReading-Eintraegen) mit dem ueber die Viessmann-API
 * ermittelten Verbrauch im selben Zeitraum, plus die Abweichung in Prozent
 * (MeterCorrectionService, bisher nur ueber das Hilfs-Command
 * report:meter-interval abrufbar, siehe Fahrplan Phase 4).
 *
 * Zaehlerstaende fallen nur unregelmaessig an (keine Tagesgranularitaet wie
 * bei den anderen Chart-Widgets), daher ein Balkendiagramm pro Intervall
 * statt einer Zeitreihe. Die Abweichung wird als Linie auf einer zweiten
 * y-Achse ueber die Balken gelegt (Fabians Wunsch, 29.10.2026 - ein
 * gemeinsames Widget statt getrennter Widgets fuer Verbrauch und
 * Abweichung).
 *
 * Achtung bei der Lesart (Fabians Review 29.10.2026): die Balken sind gleich
 * breit dargestellt, obwohl die Intervalle unterschiedlich lang sind (hier
 * 1 bis 4 Tage) - ein kurzes und ein langes Intervall sehen dadurch optisch
 * gleich "schwer" aus. Da eine echte proportionale Zeitachse bei
 * kategorialen Chart.js-Balken nicht sinnvoll umsetzbar ist, wird die
 * Intervall-Dauer stattdessen im Tooltip mit angezeigt (afterTitle), damit
 * man die Werte nicht ohne diesen Kontext vergleicht.
 *
 * Farbgebung seit Fabians Wunsch (30.10.2026): beide Balken bilden
 * Stromverbrauch ab (Zaehler vs. App-Meldung) und bekommen daher die gruene
 * Farbfamilie der App (siehe ConsumptionChartWidget), unterschieden ueber
 * Helligkeit. Die Abweichungs-Linie auf der zweiten Achse bleibt bewusst in
 * der Rot-Orange-Highlight-Farbe (--vic-color__highlight: #cc2c08), damit
 * sie sich von den beiden Balken klar abhebt statt in der Gruenfamilie
 * unterzugehen.
 */
class MeterDeviationChartWidget extends ChartWidget
{
    protected static ?int $sort = 18;

    protected ?string $heading = 'Energie Verbrauch (Zähler) & App-Abweichung';

    // Halbe Breite (Dashboard-Grid hat 2 Spalten) - reiht sich damit neben
    // die anderen drei Chart-Widgets ein (Abschnitt 4.18).
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

    public function getMaxHeight(): ?string
    {
        return '300px';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $correction = app(MeterCorrectionService::class);

        $readings = MeterReading::query()->orderBy('timestamp')->get();

        $labels = [];
        $meterValues = [];
        $apiValues = [];
        $deviationValues = [];
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

            // Bewusst nicht result['difference_percent'] (das ist die
            // umgekehrte Perspektive meter/api aus Phase 4) - hier soll
            // "App-Abweichung" exakt wie in Fabians Excel die Abweichung der
            // App VOM Zaehler ausdruecken: (App - Zaehler) / Zaehler * 100.
            $deviationValues[] = ($result['api_kwh'] !== null && $result['meter_kwh'] > 0)
                ? round((($result['api_kwh'] - $result['meter_kwh']) / $result['meter_kwh']) * 100, 2)
                : null;
        }

        return [
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => 'Verbrauch Zähler (kWh)',
                    'data' => $meterValues,
                    'backgroundColor' => '#43a047',
                    'yAxisID' => 'y',
                ],
                [
                    'type' => 'bar',
                    'label' => 'Verbrauch App (kWh)',
                    'data' => $apiValues,
                    'backgroundColor' => '#81c784',
                    'yAxisID' => 'y',
                ],
                [
                    'type' => 'line',
                    'label' => 'Abweichung Zähler ↔ App (%)',
                    'data' => $deviationValues,
                    'borderColor' => '#cc2c08',
                    'backgroundColor' => '#cc2c08',
                    'fill' => false,
                    'yAxisID' => 'y1',
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
                    type: 'linear',
                    position: 'left',
                    ticks: {
                        callback: (value) => value + ' kWh',
                    },
                },
                y1: {
                    type: 'linear',
                    position: 'right',
                    grid: {
                        drawOnChartArea: false,
                    },
                    ticks: {
                        callback: (value) => value + ' %',
                    },
                },
            },
        }
        JS);
    }
}
