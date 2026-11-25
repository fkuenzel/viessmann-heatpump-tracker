<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FormatsKpiComparison;
use App\Services\PeriodReportService;
use App\Support\ReportPeriodFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Zaehler-basierte Kennzahlen des gewaehlten Dashboard-Zeitraums (Release
 * 2.0.0, ersetzt MeterTotalsWidget, das immer ueber alle Ablesungen rechnete).
 *
 * Zaehlerwerte gibt es nur pro Ablese-Intervall - es zaehlen nur Intervalle,
 * die vollstaendig im Zeitraum liegen. Die Abdeckung steht in der ersten
 * Kachel ("6 von 7 Tagen mit Zaehlerwerten").
 */
class PeriodMeterKpiWidget extends StatsOverviewWidget
{
    use FormatsKpiComparison;
    use InteractsWithPageFilters;

    protected static ?int $sort = 0;

    protected ?string $heading = 'Zählerwerte im Zeitraum';

    protected function getStats(): array
    {
        $period = ReportPeriodFilters::resolve($this->pageFilters);
        $data = app(PeriodReportService::class)->reportWithComparison($period);
        $meter = $data['current']['meter'];
        $before = $data['previous']['meter'] ?? [];

        $consumption = $this->comparison($meter['consumption_kwh'], $before['consumption_kwh'] ?? null, $period, 1, ' kWh');
        $cost = $this->comparison($meter['cost_eur'], $before['cost_eur'] ?? null, $period, 2, ' €');
        $cop = $this->comparison($meter['cop'], $before['cop'] ?? null, $period, 2, '', true);
        $thermal = $this->comparison($meter['thermal_kwh'], $before['thermal_kwh'] ?? null, $period, 1, ' kWh', true);

        $stats = [
            Stat::make('Verbrauch (Zähler)', $this->number($meter['consumption_kwh'], 1, ' kWh'))
                ->description(trim(($consumption['text'] ?? '') . ' · ' . $meter['coverage_label'], ' ·'))
                ->descriptionIcon($consumption['icon'])
                ->color($consumption['color']),
        ];

        if ($meter['has_tariff']) {
            $costDescription = $cost['text'];

            if ($meter['cost_extrapolated_eur'] !== null) {
                $costDescription = trim(($costDescription ?? '') . ' · hochgerechnet ' . $this->number($meter['cost_extrapolated_eur'], 2, ' €'), ' ·');
            }

            $stats[] = Stat::make('Kosten (Zähler)', $this->number($meter['cost_eur'], 2, ' €'))
                ->description($costDescription)
                ->descriptionIcon($cost['icon'])
                ->color($cost['color']);
        }

        $stats[] = Stat::make('Wärme (Zähler-Intervalle)', $this->number($meter['thermal_kwh'], 1, ' kWh'))
            ->description($thermal['text'])
            ->descriptionIcon($thermal['icon'])
            ->color($thermal['color']);
        $stats[] = Stat::make('COP (Zähler)', $this->number($meter['cop'], 2))
            ->description($cop['text'])
            ->descriptionIcon($cop['icon'])
            ->color($cop['color']);

        return $stats;
    }
}
