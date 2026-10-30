<?php

namespace App\Filament\Pages;

use App\Services\ViessmannApiClient;
use App\Services\ViessmannSyncService;
use App\Services\WeatherSyncService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
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
                Select::make('days')
                    ->label('Zeitraum')
                    ->options([
                        '7' => 'Letzte 7 Tage',
                        '30' => 'Letzte 30 Tage',
                        '90' => 'Letzte 90 Tage',
                    ])
                    ->default('30')
                    ->selectablePlaceholder(false),
            ]);
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
