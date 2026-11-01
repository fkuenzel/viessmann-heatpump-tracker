<?php

namespace App\Filament\WeatherData;

use App\Models\WeatherLog;
use App\Services\ApiLogAggregationService;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

/**
 * Taeglicher Temperaturvergleich Anlagen-Aussensensor vs. externe Wetterdaten
 * (Open-Meteo-Archiv, echte Tagesmittelwerte - nicht die stuendlichen
 * live_snapshot-Zeilen) im historischen Abschnitt der Seite "Wetterdaten"
 * Wunsch, 30.10.2026 - "muss nicht im Dashboard sein, aber vll. in einer
 * eigenen Seite", spaeter zu "Wetterdaten" erweitert), siehe App\Filament\Pages\WeatherData.
 *
 * Bewusst NICHT unter app/Filament/Widgets/ abgelegt (der Pfad, den
 * AdminPanelProvider::discoverWidgets() scannt) - so erscheint dieses Widget
 * nicht automatisch auf dem Haupt-Dashboard, sondern wird in der eigenen
 * Seiten-View per @livewire() eingebunden.
 *
 * Eigenes Chart-Filter-Dropdown (getFilters()) statt des globalen
 * Dashboard-Zeitraum-Filters (Filament\Pages\Dashboard::filtersForm()) -
 * diese Seite teilt sich keinen Filter mit den Dashboard-Charts. Filter-
 * Optionen 7/14/30/90 Tage (Fabians Wunsch, 30.10.2026 - vorher 30/90/180).
 *
 * maxHeight gesetzt (Fabians Beobachtung, 30.10.2026): ohne feste Hoehe
 * waehlt Chart.js ein festes Seitenverhaeltnis - auf dieser Seite steht das
 * Chart alleine in voller Breite (columnSpan 'full', kein Dashboard-2-
 * Spalten-Grid wie bei den uebrigen Chart-Widgets), wodurch es ohne Deckel
 * unverhaeltnismaessig hoch wurde.
 */
class TemperatureChartWidget extends ChartWidget
{
    protected ?string $heading = 'Temperaturvergleich: Anlage vs. extern (Kleve)';

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    public ?string $filter = '30';

    protected function getFilters(): ?array
    {
        return [
            '7' => 'Letzte 7 Tage',
            '14' => 'Letzte 14 Tage',
            '30' => 'Letzte 30 Tage',
            '90' => 'Letzte 90 Tage',
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $days = (int) ($this->filter ?? 30);

        $lastDay = now()->subDay()->startOfDay();
        $requestedStart = $lastDay->copy()->subDays($days - 1);

        // Zeitraum an den tatsaechlich vorhandenen Archiv-Daten ausrichten,
        // statt starr $days Tage zurueckzurechnen - analog zu den Dashboard-
        // Charts (ApiLogAggregationService::earliestReferenceDate(),
        // Fahrplan Abschnitt 4.5/Phase 5).
        $earliestExtern = WeatherLog::query()
            ->where('source', 'archive_daily')
            ->min('timestamp');
        $earliestExtern = $earliestExtern ? Carbon::parse($earliestExtern)->startOfDay() : null;

        $cursor = ($earliestExtern && $earliestExtern->greaterThan($requestedStart) && $earliestExtern->lessThanOrEqualTo($lastDay))
            ? $earliestExtern->copy()
            : $requestedStart;

        $aggregation = app(ApiLogAggregationService::class);

        $labels = [];
        $anlage = [];
        $extern = [];

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $labels[] = $cursor->format('d.m.');

            $anlage[] = $aggregation->averageOutdoorTemp($cursor);

            $externRow = WeatherLog::query()
                ->where('source', 'archive_daily')
                ->whereDate('timestamp', $cursor->toDateString())
                ->first();
            $extern[] = $externRow ? (float) $externRow->temperature : null;

            $cursor = $cursor->copy()->addDay();
        }

        return [
            'datasets' => [
                [
                    'label' => 'Anlage (Außensensor)',
                    'data' => $anlage,
                    'borderColor' => '#e53935',
                    'backgroundColor' => '#e5393533',
                    'fill' => false,
                ],
                [
                    'label' => 'Extern (Kleve, Archiv)',
                    'data' => $extern,
                    'borderColor' => '#1e88e5',
                    'backgroundColor' => '#1e88e533',
                    'fill' => false,
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
            maintainAspectRatio: false,
            scales: {
                y: {
                    ticks: {
                        callback: (value) => value + ' °C',
                    },
                },
            },
        }
        JS);
    }
}
