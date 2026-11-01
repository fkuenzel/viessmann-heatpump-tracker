<?php

namespace App\Providers\Filament;

use App\Models\Setting;
use App\Models\WeatherLog;
use App\Services\ApiLogAggregationService;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Amber,
            ])
            // Reihenfolge der Sidebar-Navigationsgruppen: "Daten erfassen" steht vor
            // "Konfiguration", da Fabian diese Gruppe haeufiger nutzt (Fabians Wunsch
            // 30.10.2026). Ohne diesen Aufruf sortiert Filament die Gruppen alphabetisch,
            // wodurch "Konfiguration" faelschlich vor "Daten erfassen" gestanden haette.
            ->navigationGroups([
                NavigationGroup::make('Daten erfassen'),
                NavigationGroup::make('Konfiguration'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            // App\Filament\Pages\Dashboard (eigene Klasse statt Filaments
            // Standard-Dashboard) liegt bereits in diesem Ordner und wird
            // dadurch automatisch mitentdeckt - kein separater ->pages([...])-
            // Aufruf mehr noetig (29.10.2026, globaler Zeitraum-Filter +
            // Sync-Button im Dashboard-Header, siehe dortige Kommentare).
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // Filaments Standard-Widgets (Willkommen/Account, Filament-Versionsinfo)
            // entfernt - Fabian wollte die MeterTotalsWidget-Kacheln ganz oben
            // als Erstes sehen, nicht erst nach diesen beiden (29.10.2026).
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // Content nutzt die volle Breite statt auf 7xl (1280px) zentriert zu werden.
            // Gemessen (29.10.2026, Fabians Review): bei 1920px Breite blieben so ca.
            // 150px ungenutzter Leerraum links UND rechts vom eigentlichen Dashboard-Inhalt,
            // dadurch wirkte der Content-Bereich, als würde er "zu spät" beginnen.
            ->maxContentWidth(Width::Full)
            // Sidebar auf dem Desktop ein-/ausklappbar (Fabians Wunsch 29.10.2026).
            ->sidebarCollapsibleOnDesktop()
            // Sidebar bekommt einen eigenen Hintergrund + durchgehenden rechten Rand.
            // Ursache des "Sidebar wirkt kürzer als der Content"-Eindrucks (Fabians Review
            // 29.10.2026): .fi-sidebar ist zwar bereits (korrekt) sticky über die volle
            // Höhe positioniert, hat aber standardmäßig einen transparenten Hintergrund -
            // nur die einzelnen Nav-Items haben eine eigene Hover-/Active-Füllung. Dadurch
            // verschwimmt der Bereich unterhalb der Navigation optisch mit dem Content-
            // Hintergrund, sobald man weiter nach unten scrollt als die Nav-Liste reicht.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => <<<'HTML'
                    <style>
                        .fi-sidebar {
                            background-color: rgb(249 250 251);
                            border-inline-end: 1px solid rgb(228 228 231);
                            /* Sidebar bleibt beim Scrollen des Dashboard-Inhalts stehen statt
                               mitzuscrollen (Fabians Wunsch 30.10.2026) - auf Full-HD passt die
                               gesamte Sidebar inkl. Geraete-Stammdaten-Box ohnehin in die
                               Bildschirmhoehe, bei kleineren Bildschirmen/mehr Nav-Punkten
                               scrollt stattdessen nur die Sidebar selbst (overflow-y: auto).
                               top/height beruecksichtigen die Topbar-Hoehe (Filament-Standard
                               4rem/h-16) - ohne Abzug ragte die Sidebar mit reinem 100vh unten
                               ueber den sichtbaren Bereich hinaus (Fabians Hinweis 30.10.2026). */
                            position: sticky;
                            top: 4rem;
                            height: calc(100vh - 4rem);
                            overflow-y: auto;
                        }
                        .dark .fi-sidebar {
                            background-color: rgb(24 24 27);
                            border-inline-end-color: rgb(39 39 42);
                        }

                        .fi-topbar-weather {
                            display: flex;
                            align-items: center;
                            gap: 0.625rem;
                            margin-inline-end: 0.75rem;
                            padding-inline: 0.625rem;
                            padding-block: 0.375rem;
                            border-radius: 0.5rem;
                            background-color: rgb(249 250 251);
                            border: 1px solid rgb(228 228 231);
                            font-size: 0.8125rem;
                            line-height: 1.25rem;
                            color: rgb(63 63 70);
                            white-space: nowrap;
                        }
                        .dark .fi-topbar-weather {
                            background-color: rgb(24 24 27);
                            border-color: rgb(39 39 42);
                            color: rgb(212 212 216);
                        }
                        .fi-topbar-weather-group {
                            display: flex;
                            align-items: center;
                            gap: 0.25rem;
                        }
                        .fi-topbar-weather-sep {
                            width: 1px;
                            height: 1rem;
                            background-color: rgb(228 228 231);
                        }
                        .dark .fi-topbar-weather-sep {
                            background-color: rgb(63 63 70);
                        }
                        .fi-topbar-weather-icon {
                            width: 1rem;
                            height: 1rem;
                            flex-shrink: 0;
                            color: rgb(161 161 170);
                        }
                        @media (max-width: 640px) {
                            .fi-topbar-weather {
                                display: none;
                            }
                        }
                    </style>
                    HTML,
            )
            // Aktuelle Außentemperatur/-luftfeuchtigkeit als kleines Icon-System im
            // globalen Header, angelehnt an die Darstellung in der Viessmann-App
            // (Fabians Wunsch 30.10.2026). Zeigt beide vorhandenen Quellen nebeneinander
            // (Fabians Entscheidung): den Anlagen-Außensensor (Thermometer-Symbol) und die
            // externe Kleve-Messung von Open-Meteo (Thermometer + Tropfen fuer
            // Luftfeuchtigkeit). Bewusst feste/statische Symbole statt eines dynamischen
            // Wettericons, da WeatherSyncService keinen Wetter-Code von Open-Meteo abruft
            // (nur temperature_2m/relative_humidity_2m), ein Icon je Wetterlage also einen
            // zusaetzlichen API-Aufruf erfordert hätte. Sitzt im Topbar-Bereich "Ende" vor
            // Suche/Benachrichtigungen/Benutzermenü und ist auf allen Seiten sichtbar, nicht
            // nur auf dem Dashboard.
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                function (): string {
                    $aggregation = app(ApiLogAggregationService::class);
                    $anlageTemp = $aggregation->lastSnapshotValue(now(), 'outdoor_temp');

                    $anlageTempFormatted = $anlageTemp !== null
                        ? number_format($anlageTemp, 1, ',', '.') . ' °C'
                        : '–';

                    $thermometerIcon = <<<'SVG'
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="fi-topbar-weather-icon">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 14.5V4a2 2 0 1 0-4 0v10.5a4 4 0 1 0 4 0Z" />
                        </svg>
                        SVG;

                    // Externer Teil (Open-Meteo) nur anzeigen, wenn ein Wetter-Standort
                    // hinterlegt ist (Github-Fahrplan Abschnitt 3/4) - ohne Standort gibt
                    // es keine weather_log-Zeilen, der Block wuerde sonst dauerhaft nur
                    // Platzhalter-Striche zeigen.
                    $externalBlock = '';

                    if (Setting::get('weather_latitude') !== null) {
                        $location = WeatherLog::query()->latest('timestamp')->first();
                        $locationLabel = Setting::get('weather_location_name') ?? 'extern';

                        $locationTempFormatted = $location
                            ? number_format((float) $location->temperature, 1, ',', '.') . ' °C'
                            : '–';

                        $locationHumidityFormatted = $location
                            ? number_format((float) $location->humidity, 0, ',', '.') . ' %'
                            : '–';

                        $dropletIcon = <<<'SVG'
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="fi-topbar-weather-icon">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5s6 6.5 6 10.5a6 6 0 0 1-12 0c0-4 6-10.5 6-10.5Z" />
                            </svg>
                            SVG;

                        $externalBlock = <<<HTML
                            <span class="fi-topbar-weather-sep"></span>
                            <span class="fi-topbar-weather-group" title="Außentemperatur &amp; Luftfeuchtigkeit ({$locationLabel})">
                                {$thermometerIcon}
                                <span>{$locationTempFormatted}</span>
                                {$dropletIcon}
                                <span>{$locationHumidityFormatted}</span>
                            </span>
                            HTML;
                    }

                    return <<<HTML
                        <div class="fi-topbar-weather">
                            <span class="fi-topbar-weather-group" title="Außentemperatur (Anlage)">
                                {$thermometerIcon}
                                <span>{$anlageTempFormatted}</span>
                            </span>
                            {$externalBlock}
                        </div>
                        HTML;
                },
            )
            // Geraete-Stammdaten (Produktname/-typ/Seriennummer) unten in der Sidebar,
            // analog zur "Wärmepumpe"-Detailseite in der ViCare-App (Fabians Wunsch
            // 30.10.2026, Screenshot). Werte kommen live aus der Viessmann-API
            // (ViessmannApiClient::getDeviceInfo()), werden aber nur einmalig beim
            // Sync in die settings-Tabelle geschrieben (ViessmannSyncService::
            // refreshDeviceInfo()) statt bei jedem Seitenaufruf neu abgerufen - die
            // Sidebar liest hier nur noch die gecachten settings-Werte, read-only,
            // kein eigenes Formular dafuer.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_FOOTER,
                fn (): \Illuminate\Contracts\View\View => view('filament.partials.device-info-sidebar'),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
