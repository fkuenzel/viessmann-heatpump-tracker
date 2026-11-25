<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\FormatsKpiComparison;
use App\Services\PeriodReportService;
use App\Support\ReportPeriodFilters;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * App-basierte, tagesgenaue Kennzahlen des gewaehlten Dashboard-Zeitraums
 * (Strom und Thermik laut Viessmann-API, Wetter, Kompressor, Heizstab).
 */
class PeriodAppKpiWidget extends StatsOverviewWidget
{
    use FormatsKpiComparison;
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;

    protected ?string $heading = 'Anlagenwerte im Zeitraum (App, tagesgenau)';

    protected function getStats(): array
    {
        $period = ReportPeriodFilters::resolve($this->pageFilters);
        $data = app(PeriodReportService::class)->reportWithComparison($period);
        $cur = $data['current'];
        $prev = $data['previous'] ?? ['app' => [], 'weather' => [], 'compressor' => [], 'heating_rod' => []];

        $power = $this->comparison($cur['app']['power_total_kwh'], $prev['app']['power_total_kwh'] ?? null, $period, 1, ' kWh');
        $thermal = $this->comparison($cur['app']['thermal_total_kwh'], $prev['app']['thermal_total_kwh'] ?? null, $period, 1, ' kWh', true);
        $cop = $this->comparison($cur['app']['cop'], $prev['app']['cop'] ?? null, $period, 2, '', true);
        $perHdd = $this->comparison($cur['weather']['heating_kwh_per_degree_day'], $prev['weather']['heating_kwh_per_degree_day'] ?? null, $period, 2, ' kWh');
        $starts = $this->comparison($cur['compressor']['starts'] !== null ? (float) $cur['compressor']['starts'] : null, isset($prev['compressor']['starts']) ? (float) $prev['compressor']['starts'] : null, $period, 0);

        $powerDescription = 'Ø ' . $this->number($cur['app']['power_avg_per_day_kwh'], 1, ' kWh/Tag');

        if ($power['text'] !== null) {
            $powerDescription .= ' · ' . $power['text'];
        }

        if ($cur['period']['includes_today']) {
            $powerDescription .= ' · inkl. laufendem Tag';
        }

        $stats = [
            Stat::make('Strom (App)', $this->number($cur['app']['power_total_kwh'], 1, ' kWh'))
                ->description($powerDescription)
                ->descriptionIcon($power['icon'])
                ->color($power['color']),
            Stat::make('Wärme (App)', $this->number($cur['app']['thermal_total_kwh'], 1, ' kWh'))
                ->description($thermal['text'] ?? ($cur['app']['thermal_days'] . ' Tage mit Wärmewerten'))
                ->descriptionIcon($thermal['icon'])
                ->color($thermal['color']),
            Stat::make('COP (App)', $this->number($cur['app']['cop'], 2))
                ->description($cop['text'] ?? ($cur['app']['cop_days'] . ' Tage ausgewertet'))
                ->descriptionIcon($cop['icon'])
                ->color($cop['color']),
            Stat::make('Außentemperatur Ø', $this->number($cur['weather']['avg_temperature_celsius'], 1, ' °C'))
                ->description($this->number($cur['weather']['heating_degree_days'], 0) . ' Heizgradtage'),
            Stat::make('Heizen je Heizgradtag', $this->number($cur['weather']['heating_kwh_per_degree_day'], 2, ' kWh'))
                ->description($perHdd['text'] ?? 'Witterungsbereinigt, niedriger ist besser')
                ->descriptionIcon($perHdd['icon'])
                ->color($perHdd['color']),
            Stat::make('Verdichterstarts', $cur['compressor']['starts'] !== null ? (string) $cur['compressor']['starts'] : '–')
                ->description(trim(($starts['text'] ?? '') . ($cur['compressor']['hours'] !== null ? ' · ' . $this->number($cur['compressor']['hours'], 0, ' h Laufzeit') : ''), ' ·'))
                ->descriptionIcon($starts['icon'])
                ->color('gray'),
        ];

        if (($cur['heating_rod']['kwh'] ?? null) !== null) {
            $stats[] = Stat::make('Heizstab', $this->number($cur['heating_rod']['kwh'], 2, ' kWh'))
                ->description($cur['heating_rod']['days'] . ' Tage erfasst')
                ->color(($cur['heating_rod']['kwh'] ?? 0) > 0 ? 'warning' : 'gray');
        }

        return $stats;
    }
}
