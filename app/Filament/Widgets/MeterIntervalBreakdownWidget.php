<?php

namespace App\Filament\Widgets;

use App\Models\MeterReading;
use App\Models\PriceHistory;
use App\Services\MeterIntervalBreakdownService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

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
                        : 'Intervall unter ' . number_format(MeterIntervalBreakdownService::MIN_RELIABLE_INTERVAL_DAYS, 0) . ' Tag: Thermische Energie ist ein Tages-Snapshot, der COP ist für so kurze Intervalle nicht aussagekräftig.')
                    ->description(fn (MeterReading $record) => $this->plainHint($this->breakdown($record)['app_cop'], null)),
            ])
            ->defaultPaginationPageOption(5);
    }

    private function previousReading(MeterReading $record): ?MeterReading
    {
        return app(MeterIntervalBreakdownService::class)->previousReading($record);
    }

    /**
     * Die eigentliche Berechnung liegt seit Version 1.1.0 in
     * App\Services\MeterIntervalBreakdownService, damit der MCP-Server
     * dieselben Werte liefert wie dieses Widget. Das Ergebnis wird hier
     * pro Zeile gecacht, da jede Spalte es erneut abfragt.
     *
     * @return array<string, mixed>
     */
    private function breakdown(MeterReading $record): array
    {
        return $this->breakdownCache[$record->id]
            ??= app(MeterIntervalBreakdownService::class)->breakdown($record);
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
