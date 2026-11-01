<?php

namespace App\Filament\Widgets;

use App\Models\MeterReading;
use App\Models\PriceHistory;
use App\Services\ApiLogAggregationService;
use App\Services\CostCalculationService;
use App\Services\MeterCorrectionService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;

/**
 * Reale Aufteilung Heizen/Warmwasser und COP auf Basis des echten
 * Zaehler-Verbrauchs statt des App-/API-gemeldeten Verbrauchs, pro
 * Ablese-Intervall - wie bisher in Fabians Excel-Tabelle (Blatt "HeizStrom")
 * gepflegt (Fahrplan Abschnitt 4.21/4.24). Ergaenzt das
 * MeterDeviationChartWidget (Verbrauch Zaehler vs. App + Abweichung) um die
 * Werte, die sich aus dem Heizen-%/WW%-Anteil der App-Daten UND dem echten
 * Zaehler-Verbrauch ableiten lassen: Anteil (%), echte Aufteilung in kWh,
 * echter COP (Thermik / Zaehler-Verbrauch statt Thermik / App-Verbrauch).
 *
 * Bewusst als eigenes Tabellen-Widget statt zusaetzlicher Chart-Linien
 * (Fabians Entscheidung, 29.10.2026) - kWh, % und COP in einem Chart waeren
 * mit einer dritten Achse schnell unuebersichtlich geworden.
 *
 * Ueberarbeitet nach Fabians Feedback (29.10.2026, Abschnitt 4.24):
 * Tabellen-Header duerfen umbrechen (wrapHeader()), alle Dezimalwerte im
 * deutschen Format mit 2 Nachkommastellen, Spalte "Dauer (Tage)" wie im
 * Excel ergaenzt, und unter Heizen %/WW %/Echter Verbrauch Heizen+WW/Echter
 * COP steht jetzt als helle Zusatzzeile (->description()) der jeweilige
 * App-Wert zum direkten Vergleich.
 */
class MeterIntervalBreakdownWidget extends TableWidget
{
    protected static ?int $sort = 20;

    /**
     * Thermische Energie ist ein Tages-Snapshot ohne untertaegige Aufloesung
     * (Abschnitt 4.10) - bei Intervallen unter dieser Dauer wird dem
     * Intervall trotzdem der volle Tageswert zugerechnet, was den COP
     * (Thermik / sehr kleiner Zaehler-Verbrauch) stark verzerrt. Fund
     * 29.10.2026 (Fabians zweite Ablesung desselben Tages ergab ein
     * 0,23-Tage-Intervall mit einem COP von ueber 20) - unterhalb dieser
     * Schwelle wird "Echter COP" entsprechend mit einem Warnhinweis
     * markiert statt den Wert kommentarlos anzuzeigen.
     */
    private const MIN_RELIABLE_INTERVAL_DAYS = 1.0;

    protected int | string | array $columnSpan = 'full';

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $breakdownCache = [];

    protected function getTableHeading(): ?string
    {
        return 'Reale Aufteilung & COP (Zähler-Basis)';
    }

    public function table(Table $table): Table
    {
        $firstId = MeterReading::query()->orderBy('timestamp')->value('id');

        return $table
            ->query(
                MeterReading::query()
                    ->when($firstId, fn ($query) => $query->where('id', '!=', $firstId))
                    ->orderByDesc('timestamp')
            )
            ->columns([
                TextColumn::make('zeitraum')
                    ->label('Zeitraum')
                    ->wrapHeader()
                    ->state(function (MeterReading $record) {
                        $previous = $this->previousReading($record);

                        return $previous
                            ? $previous->timestamp->format('d.m.Y') . ' – ' . $record->timestamp->format('d.m.Y')
                            : '–';
                    }),
                TextColumn::make('duration_days')
                    ->label('Dauer (Tage)')
                    ->wrapHeader()
                    ->state(fn (MeterReading $record) => $this->formatNumber($this->breakdown($record)['duration_days'])),
                TextColumn::make('avg_kwh_per_day')
                    ->label('Ø kWh/Tag')
                    ->wrapHeader()
                    ->state(fn (MeterReading $record) => $this->formatNumber($this->breakdown($record)['avg_kwh_per_day']) . ' kWh'),
                TextColumn::make('delta_ht_kwh')
                    ->label('Verbrauch HT (kWh)')
                    ->wrapHeader()
                    ->state(fn (MeterReading $record) => $this->formatNumber($this->breakdown($record)['delta_ht_kwh']) . ' kWh'),
                TextColumn::make('delta_nt_kwh')
                    ->label('Verbrauch NT (kWh)')
                    ->wrapHeader()
                    ->state(fn (MeterReading $record) => $this->formatNumber($this->breakdown($record)['delta_nt_kwh']) . ' kWh'),
                TextColumn::make('api_kwh')
                    ->label('Verbrauch App Gesamt (kWh)')
                    ->wrapHeader()
                    ->state(fn (MeterReading $record) => $this->formatNumber($this->breakdown($record)['api_kwh']) . ' kWh'),
                TextColumn::make('heating_percent')
                    ->label('Heizen %')
                    ->wrapHeader()
                    ->state(fn (MeterReading $record) => $this->formatPercent($this->breakdown($record)['heating_percent']))
                    ->description(fn (MeterReading $record) => $this->plainHint($this->breakdown($record)['heating_percent_cumulative'], '%')),
                TextColumn::make('dhw_percent')
                    ->label('WW %')
                    ->wrapHeader()
                    ->state(fn (MeterReading $record) => $this->formatPercent($this->breakdown($record)['dhw_percent']))
                    ->description(fn (MeterReading $record) => $this->plainHint($this->breakdown($record)['dhw_percent_cumulative'], '%')),
                TextColumn::make('real_heating_kwh')
                    ->label('Heizen (kWh)')
                    ->wrapHeader()
                    ->state(fn (MeterReading $record) => $this->formatNumber($this->breakdown($record)['real_heating_kwh']) . ' kWh')
                    ->description(fn (MeterReading $record) => $this->plainHint($this->breakdown($record)['app_heating_kwh'], 'kWh')),
                TextColumn::make('real_dhw_kwh')
                    ->label('WW (kWh)')
                    ->wrapHeader()
                    ->state(fn (MeterReading $record) => $this->formatNumber($this->breakdown($record)['real_dhw_kwh']) . ' kWh')
                    ->description(fn (MeterReading $record) => $this->plainHint($this->breakdown($record)['app_dhw_kwh'], 'kWh')),
                TextColumn::make('total_cost')
                    ->label('Kosten Gesamt (€)')
                    ->wrapHeader()
                    ->visible(fn () => PriceHistory::query()->exists())
                    ->state(fn (MeterReading $record) => $this->formatEuro($this->breakdown($record)['total_cost'])),
                TextColumn::make('heating_cost')
                    ->label('Kosten Heizen (€)')
                    ->wrapHeader()
                    ->visible(fn () => PriceHistory::query()->exists())
                    ->state(fn (MeterReading $record) => $this->formatEuro($this->breakdown($record)['heating_cost'])),
                TextColumn::make('dhw_cost')
                    ->label('Kosten WW (€)')
                    ->wrapHeader()
                    ->visible(fn () => PriceHistory::query()->exists())
                    ->state(fn (MeterReading $record) => $this->formatEuro($this->breakdown($record)['dhw_cost'])),
                TextColumn::make('thermal_kwh')
                    ->label('Thermische Energie (kWh)')
                    ->wrapHeader()
                    ->state(fn (MeterReading $record) => $this->formatNumber($this->breakdown($record)['thermal_kwh']) . ' kWh'),
                TextColumn::make('real_cop')
                    ->label('Echter COP')
                    ->wrapHeader()
                    ->state(fn (MeterReading $record) => $this->formatNumber($this->breakdown($record)['real_cop']))
                    ->color(fn (MeterReading $record) => $this->breakdown($record)['reliable'] ? null : 'warning')
                    ->icon(fn (MeterReading $record) => $this->breakdown($record)['reliable'] ? null : 'heroicon-o-exclamation-triangle')
                    ->tooltip(fn (MeterReading $record) => $this->breakdown($record)['reliable']
                        ? null
                        : 'Intervall unter ' . number_format(self::MIN_RELIABLE_INTERVAL_DAYS, 0) . ' Tag: Thermische Energie ist ein Tages-Snapshot, der COP ist für so kurze Intervalle nicht aussagekräftig.')
                    ->description(fn (MeterReading $record) => $this->plainHint($this->breakdown($record)['app_cop'], null)),
            ])
            ->defaultPaginationPageOption(5);
    }

    private function previousReading(MeterReading $record): ?MeterReading
    {
        return MeterReading::query()
            ->where('timestamp', '<', $record->timestamp)
            ->orderByDesc('timestamp')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function breakdown(MeterReading $record): array
    {
        if (isset($this->breakdownCache[$record->id])) {
            return $this->breakdownCache[$record->id];
        }

        $empty = [
            'duration_days' => null,
            'avg_kwh_per_day' => null,
            'delta_ht_kwh' => null,
            'delta_nt_kwh' => null,
            'api_kwh' => null,
            'heating_percent' => null,
            'dhw_percent' => null,
            'heating_percent_cumulative' => null,
            'dhw_percent_cumulative' => null,
            'real_heating_kwh' => null,
            'real_dhw_kwh' => null,
            'thermal_kwh' => null,
            'real_cop' => null,
            'app_heating_kwh' => null,
            'app_dhw_kwh' => null,
            'app_cop' => null,
            'total_cost' => null,
            'heating_cost' => null,
            'dhw_cost' => null,
            'reliable' => null,
        ];

        $previous = $this->previousReading($record);

        if (! $previous) {
            return $this->breakdownCache[$record->id] = $empty;
        }

        $correction = app(MeterCorrectionService::class);
        $aggregation = app(ApiLogAggregationService::class);
        $cost = app(CostCalculationService::class);

        $result = $correction->calculate($previous, $record);
        $meterKwh = $result['meter_kwh'];
        $apiKwh = $result['api_kwh'];

        $durationDaysRaw = $previous->timestamp->diffInSeconds($record->timestamp) / 86400;
        $avgKwhPerDay = $durationDaysRaw > 0 ? $meterKwh / $durationDaysRaw : null;

        $heatingSum = $aggregation->weightedSum($previous->timestamp, $record->timestamp, 'power_heating_kwh')['value'];
        $dhwSum = $aggregation->weightedSum($previous->timestamp, $record->timestamp, 'power_dhw_kwh')['value'];

        $heatingPercent = ($apiKwh !== null && $apiKwh > 0 && $heatingSum !== null)
            ? ($heatingSum / $apiKwh) * 100
            : null;
        $dhwPercent = ($apiKwh !== null && $apiKwh > 0 && $dhwSum !== null)
            ? ($dhwSum / $apiKwh) * 100
            : null;

        $realHeatingKwh = ($heatingPercent !== null && $meterKwh !== null)
            ? $meterKwh * $heatingPercent / 100
            : null;
        $realDhwKwh = ($dhwPercent !== null && $meterKwh !== null)
            ? $meterKwh * $dhwPercent / 100
            : null;

        $thermalKwh = $this->thermalSum($previous->timestamp, $record->timestamp, $aggregation);

        $realCop = ($thermalKwh !== null && $meterKwh !== null && $meterKwh > 0)
            ? $thermalKwh / $meterKwh
            : null;

        $appCop = ($thermalKwh !== null && $apiKwh !== null && $apiKwh > 0)
            ? $thermalKwh / $apiKwh
            : null;

        [$heatingPercentCumulative, $dhwPercentCumulative] = $this->cumulativePercents($record, $aggregation);

        // Kosten proportional nach Heizen-%/WW%-Anteil des Intervalls
        // aufgeteilt (inkl. anteiliger Grundgebuehr) - wie in Fabians
        // bisheriger Excel-Tabelle (Spalte "Kosten Heizen/WW"), Fahrplan
        // Abschnitt 4.27. CostCalculationService::calculate() liefert nur
        // den Gesamtbetrag des Intervalls (Phase 4), die Aufteilung ist neu.
        $costResult = $cost->calculate($previous, $record);
        $totalCost = $costResult['total_cost'];
        $deltaHtKwh = $costResult['delta_ht_kwh'];
        $deltaNtKwh = $costResult['delta_nt_kwh'];
        $heatingCost = ($totalCost !== null && $heatingPercent !== null)
            ? round($totalCost * $heatingPercent / 100, 2)
            : null;
        $dhwCost = ($totalCost !== null && $dhwPercent !== null)
            ? round($totalCost * $dhwPercent / 100, 2)
            : null;

        return $this->breakdownCache[$record->id] = [
            'duration_days' => round($durationDaysRaw, 2),
            'avg_kwh_per_day' => $avgKwhPerDay !== null ? round($avgKwhPerDay, 2) : null,
            'delta_ht_kwh' => round($deltaHtKwh, 2),
            'delta_nt_kwh' => round($deltaNtKwh, 2),
            'api_kwh' => $apiKwh,
            'heating_percent' => $heatingPercent !== null ? round($heatingPercent, 2) : null,
            'dhw_percent' => $dhwPercent !== null ? round($dhwPercent, 2) : null,
            'heating_percent_cumulative' => $heatingPercentCumulative,
            'dhw_percent_cumulative' => $dhwPercentCumulative,
            'real_heating_kwh' => $realHeatingKwh !== null ? round($realHeatingKwh, 2) : null,
            'real_dhw_kwh' => $realDhwKwh !== null ? round($realDhwKwh, 2) : null,
            'thermal_kwh' => $thermalKwh !== null ? round($thermalKwh, 2) : null,
            'real_cop' => $realCop !== null ? round($realCop, 2) : null,
            'app_heating_kwh' => $heatingSum !== null ? round($heatingSum, 2) : null,
            'app_dhw_kwh' => $dhwSum !== null ? round($dhwSum, 2) : null,
            'app_cop' => $appCop !== null ? round($appCop, 2) : null,
            'total_cost' => $totalCost,
            'heating_cost' => $heatingCost,
            'dhw_cost' => $dhwCost,
            'reliable' => $durationDaysRaw >= self::MIN_RELIABLE_INTERVAL_DAYS,
        ];
    }

    /**
     * Kumulierter Heizen-%/WW%-Anteil seit Messbeginn (frueheste Ablesung)
     * bis einschliesslich diesem Intervall-Ende - analog zur GESAMT-Zeile in
     * Fabians bisheriger Excel-Tabelle, als helle Zusatzzeile unter dem
     * Intervall-eigenen Anteil (Fabians Entscheidung, 29.10.2026).
     *
     * @return array{0: ?float, 1: ?float}
     */
    private function cumulativePercents(MeterReading $record, ApiLogAggregationService $aggregation): array
    {
        $firstTimestamp = MeterReading::query()->orderBy('timestamp')->value('timestamp');

        if (! $firstTimestamp) {
            return [null, null];
        }

        $firstTimestamp = Carbon::parse($firstTimestamp);

        $heatingSum = $aggregation->weightedSum($firstTimestamp, $record->timestamp, 'power_heating_kwh')['value'];
        $dhwSum = $aggregation->weightedSum($firstTimestamp, $record->timestamp, 'power_dhw_kwh')['value'];
        $totalSum = $aggregation->weightedSum($firstTimestamp, $record->timestamp, 'power_total_kwh')['value'];

        if ($totalSum === null || $totalSum <= 0) {
            return [null, null];
        }

        return [
            $heatingSum !== null ? round(($heatingSum / $totalSum) * 100, 2) : null,
            $dhwSum !== null ? round(($dhwSum / $totalSum) * 100, 2) : null,
        ];
    }

    /**
     * Thermische Energie (Heizen + Warmwasser) ueber das Intervall, als
     * einfache Tagessumme wie in CopCalculationService::forRange() - keine
     * Rand-Intervall-Gewichtung, da Thermik ohnehin als "24 Std. Messung"
     * pro Kalendertag gefuehrt wird (Fahrplan Abschnitt 4.10/4.16).
     */
    private function thermalSum(Carbon $from, Carbon $to, ApiLogAggregationService $aggregation): ?float
    {
        $sum = 0.0;
        $hasValue = false;

        $cursor = $from->copy()->startOfDay();
        $lastDay = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $heating = $aggregation->lastSnapshotValue($cursor, 'thermal_heating_kwh');
            $dhw = $aggregation->lastSnapshotValue($cursor, 'thermal_dhw_kwh');

            if ($heating !== null || $dhw !== null) {
                $sum += ($heating ?? 0) + ($dhw ?? 0);
                $hasValue = true;
            }

            $cursor->addDay();
        }

        return $hasValue ? $sum : null;
    }

    /**
     * Deutsches Zahlenformat (Komma, 2 Nachkommastellen) - Fabians Wunsch,
     * 29.10.2026.
     */
    private function formatNumber(?float $value): string
    {
        return $value === null ? '–' : number_format($value, 2, ',', '.');
    }

    private function formatPercent(?float $value): string
    {
        return $value === null ? '–' : number_format($value, 2, ',', '.') . ' %';
    }

    private function formatEuro(?float $value): string
    {
        return $value === null ? '–' : number_format($value, 2, ',', '.') . ' €';
    }

    /**
     * Helle Zusatzzeile unter Heizen %/WW %/Echter Verbrauch Heizen+WW/
     * Echter COP - nur die Zahl, ohne Praefix wie "App:" (Fabians
     * Entscheidung, 29.10.2026 - der vorherige Praefix wirkte auf Dauer
     * nervig).
     */
    private function plainHint(?float $value, ?string $unit): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format($value, 2, ',', '.') . ($unit ? ' ' . $unit : '');
    }
}
