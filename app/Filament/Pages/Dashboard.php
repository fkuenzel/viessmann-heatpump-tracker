<?php

namespace App\Filament\Pages;

use App\Services\ViessmannApiClient;
use App\Services\ViessmannSyncService;
use App\Services\WeatherSyncService;
use Filament\Actions\Action;
use App\Support\ReportPeriod;
use App\Support\ReportPeriodFilters;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Ueberschreibt Filaments Standard-Dashboard-Seite um zwei Dinge, die bei
 * Fabians Review (29.10.2026) fehlten:
 *
 * 1. Ein globaler Zeitraum-Filter (7/30/90 Tage), der fuer ConsumptionChart-,
 *    ThermalProductionChart- und CopChartWidget gemeinsam gilt (ueber
 *    Filament\Widgets\Concerns\InteractsWithPageFilters::$pageFilters) -
 *    vorher hatte jedes der drei Charts seinen eigenen Dropdown, musste also
 *    bei Bedarf dreimal einzeln umgestellt werden. MeterDeviationChartWidget/
 *    MeterIntervalBreakdownWidget bleiben bewusst aussen vor, die arbeiten
 *    auf Ablese-Intervallen statt auf Kalendertagen und haben keinen eigenen
 *    Zeitraum-Filter.
 * 2. Ein "Jetzt synchronisieren"-Button direkt im Dashboard-Header, identisch
 *    zur Action auf der separaten Sync-Seite (SyncControl) - Fabian wollte
 *    dafuer nicht extra navigieren muessen, da die App ohne Dauerbetrieb des
 *    Schedulers laeuft (Fahrplan Abschnitt 0) und der manuelle Sync dadurch
 *    ein Alltagsklick ist.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->compact()
                    ->columnSpanFull()
                    ->extraAttributes(['class' => 'w-full'])
                    ->schema([
                        Select::make('preset')
                            ->label('Zeitraum')
                            ->options(ReportPeriod::PRESETS)
                            ->default('last_30')
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->live()
                            // Beim Wechsel auf "Benutzerdefiniert" starten Von/Bis mit dem
                            // bisher gewählten Zeitraum, statt leer zu sein.
                            ->afterStateUpdated(function (string $state, ?string $old, Set $set): void {
                                // "Benutzerdefiniert" startet mit dem zuvor gewählten Zeitraum,
                                // jede andere Voreinstellung füllt Von/Bis mit ihrem Zeitraum.
                                $source = $state === 'custom'
                                    ? (array_key_exists((string) $old, ReportPeriod::PRESETS) && $old !== 'custom' ? $old : 'last_30')
                                    : $state;

                                $period = ReportPeriodFilters::resolve(['preset' => $source]);
                                $set('from', $period->from->toDateString());
                                $set('to', $period->to->toDateString());
                            }),
                        // Der Vergleich gehört zum gewählten Zeitraum und steht direkt daneben.
                        Select::make('compare')
                            ->label('Vergleich mit')
                            ->options(ReportPeriod::COMPARE_MODES)
                            ->default('previous')
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->live(),
                        // Datumsfelder nur bei benutzerdefiniertem Zeitraum.
                        DatePicker::make('from')
                            ->label('Von')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->maxDate(now())
                            ->visible(fn (Get $get): bool => $get('preset') === 'custom')
                            ->live(),
                        DatePicker::make('to')
                            ->label('Bis')
                            ->native(false)
                            ->displayFormat('d.m.Y')
                            ->maxDate(now())
                            ->visible(fn (Get $get): bool => $get('preset') === 'custom')
                            ->live(),
                    ])
                    ->columns(['default' => 1, 'md' => 4]),
            ]);
    }

    /**
     * Ueberschreibt Filaments fest verdrahtetes getColumns() (immer 2 Spalten,
     * vendor/filament/filament/src/Pages/Dashboard.php:74 - kein Breakpoint-
     * Array, sondern ein einzelner int, der unabhaengig von der Bildschirm-
     * breite gilt). Dadurch wurden auf dem Smartphone alle Widgets mit
     * columnSpan=1 (alle ChartWidgets + MeterTotalsWidget) in eine erzwungene
     * 2-Spalten-Reihe gequetscht: jede Grid-Zeile richtet ihre Hoehe am
     * hoechsten Widget der Reihe aus, wodurch unter den schmaleren/kuerzeren
     * Widgets riesige Luecken entstanden (Fabians Screenshot, 02.11.2026) -
     * genau das in freier Breite nebeneinander gedachte Layout (siehe
     * Kommentare "Halbe Breite ... Dashboard-Grid hat 2 Spalten" in den
     * einzelnen ChartWidgets) ist erst ab "md" sinnvoll, darunter soll jedes
     * Widget die volle Breite bekommen.
     *
     * @return int | array<string, int>
     */
    public function getColumns(): int | array
    {
        return [
            'default' => 1,
            'sm' => 1,
            'md' => 2,
            'lg' => 2,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('syncNow')
                ->label('Jetzt synchronisieren')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->action(function (ViessmannSyncService $viessmannSync, WeatherSyncService $weatherSync, ViessmannApiClient $client) {
                    if (! $client->isConnected()) {
                        Notification::make()
                            ->title('Viessmann-Verbindung fehlt')
                            ->body('Bitte zunaechst /viessmann/connect einmalig autorisieren.')
                            ->danger()
                            ->send();

                        return;
                    }

                    $written = $viessmannSync->sync('manual');
                    $weatherSync->sync();

                    Notification::make()
                        ->title('Sync abgeschlossen')
                        ->body("{$written} Zeilen in api_log geschrieben, Wetterdaten aktualisiert.")
                        ->success()
                        ->send();
                }),
        ];
    }
}
