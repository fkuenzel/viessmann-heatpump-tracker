<?php

namespace App\Filament\Pages;

use App\Models\ApiLog;
use App\Models\WeatherLog;
use App\Services\ViessmannApiClient;
use App\Services\ViessmannSyncService;
use App\Services\WeatherSyncService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SyncControl extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationLabel = 'Sync';

    protected static \UnitEnum | string | null $navigationGroup = 'Konfiguration';

    protected string $view = 'filament.pages.sync-control';

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

    public function getLastApiLog(): ?ApiLog
    {
        return ApiLog::query()->latest('timestamp')->first();
    }

    public function getLastWeatherLog(): ?WeatherLog
    {
        return WeatherLog::query()->latest('timestamp')->first();
    }

    public function isViessmannConnected(): bool
    {
        return app(ViessmannApiClient::class)->isConnected();
    }
}
