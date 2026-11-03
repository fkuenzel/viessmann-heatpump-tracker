<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\GeocodingService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Globale Einstellungen (settings-Tabelle als Key-Value-Store).
 *
 * meter_number ist die aktuelle Zählernummer: Sie wird hier zentral
 * gepflegt und beim Anlegen eines neuen Zählerstands (MeterReadingResource)
 * nur als Vorbelegung uebernommen und dort als konkreter Wert gespeichert -
 * nicht live referenziert. Historische Ablesungen behalten dadurch auch
 * nach einem Zaehlertausch ihre ursprüngliche Zählernummer.
 *
 * weather_location_name/weather_latitude/weather_longitude machen den
 * Wetter-Standort konfigurierbar statt fest auf Kleve im Code verdrahtet
 * zu sein (Github-Fahrplan Abschnitt 3) - Pflege per Ortsname, die
 * eigentlichen Koordinaten werden per GeocodingService im Hintergrund
 * ermittelt (Fabians Entscheidung: nutzerfreundlicher fuer andere
 * Betreiber als eine reine Koordinaten-Eingabe). Ohne hinterlegten
 * Standort bleiben die davon abhaengigen Seiten/Widgets ausgeblendet
 * (siehe App\Filament\Pages\WeatherData, Fahrplan Abschnitt 4).
 *
 * device_status_sync_interval_hours (Fabians Einwand, 03.11.2026, siehe
 * App\Services\ViessmannSyncService-Docblock): optionaler Mindestabstand
 * fuer die Geraetestatus-Historisierung in api_log, Default 0 = jeder
 * 15-Minuten-Sync (Ausgangszustand). Bewusst NICHT standardmaessig auf ein
 * groesseres Intervall gestellt, da compressor_starts/compressor_hours
 * (Teil desselben Abrufs) bereits aktiv in CompressorStartsChartWidget/
 * CompressorHoursChartWidget verwendet werden und ein groesseres Intervall
 * deren Tages-Zuordnung verschlechtern wuerde. Betrifft ohnehin nur die
 * Historie - die Live-Seite App\Filament\Pages\DeviceStatus fragt
 * unabhaengig davon immer den aktuellen Stand per API ab.
 */
class SettingsPage extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationLabel = 'Einstellungen';

    protected static \UnitEnum | string | null $navigationGroup = 'Konfiguration';

    protected string $view = 'filament.pages.settings-page';

    /**
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $this->form->fill([
            'meter_number' => Setting::get('meter_number'),
            'forecast_horizon_days' => (int) Setting::get('forecast_horizon_days', 3),
            'weather_location_name' => Setting::get('weather_location_name'),
            'mail_recipient' => Setting::get('mail_recipient'),
            'report_frequency' => Setting::get('report_frequency', 'weekly'),
            'anomaly_threshold_percent' => (float) Setting::get('anomaly_threshold_percent', 30),
            'anomaly_lookback_days' => (int) Setting::get('anomaly_lookback_days', 14),
            'sync_outage_threshold_hours' => (int) Setting::get('sync_outage_threshold_hours', 24),
            'device_status_sync_interval_hours' => (int) Setting::get('device_status_sync_interval_hours', 0),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('meter_number')
                    ->label('Aktuelle Zählernummer')
                    ->helperText('Wird als Vorbelegung bei neuen Zählerstand-Einträgen verwendet (dort als fester Wert gespeichert, nicht live verknüpft).')
                    ->maxLength(255),
                TextInput::make('forecast_horizon_days')
                    ->label('Wetterprognose-Horizont (Tage)')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(16)
                    ->required(),
                Section::make('Wetter-Standort')
                    ->description('Standort für Außentemperatur/-prognose und den Temperaturvergleich. Ortsname eingeben - die Koordinaten werden beim Speichern automatisch über Open-Meteo ermittelt. Ohne Standort bleiben die Seite "Wetterdaten" und die davon abhängigen Anzeigen ausgeblendet.')
                    ->schema([
                        TextInput::make('weather_location_name')
                            ->label('Ort')
                            ->placeholder('z. B. Kleve')
                            ->helperText(fn () => Setting::get('weather_latitude') !== null
                                ? 'Aktuell aufgelöst: ' . number_format((float) Setting::get('weather_latitude'), 4, ',', '.') . ', ' . number_format((float) Setting::get('weather_longitude'), 4, ',', '.')
                                : 'Noch kein Standort hinterlegt.')
                            ->maxLength(255),
                    ]),
                Section::make('Gerätestatus-Historisierung')
                    ->description('Vorlauf-/Rücklauftemperatur, Druck, Pumpe, Volumenstrom, Verdichterstatus und Kompressorstarts/-stunden werden zusätzlich zu den Verbrauchsdaten in der Historie gespeichert (u. a. für die Kompressor-Charts im Dashboard). Die Live-Seite "Gerätestatus" ist davon unabhängig und zeigt immer den aktuellen Stand direkt von der API.')
                    ->schema([
                        TextInput::make('device_status_sync_interval_hours')
                            ->label('Mindestabstand zwischen Abrufen')
                            ->helperText('0 = bei jedem Sync (Standard, alle 15 Minuten). Ein größerer Wert spart API-Requests, verschlechtert aber die Tages-Zuordnung der Kompressorstarts/-stunden-Charts, da der letzte Tageswert dann nicht mehr nahe Mitternacht liegt.')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(168)
                            ->suffix('Std.')
                            ->required(),
                    ]),
                Section::make('Mail-Reporting')
                    ->description('Periodische Zusammenfassung + Warnungen bei auffälligem Tagesverbrauch oder Sync-Ausfall (Fahrplan Phase 6). Ohne Empfänger-Adresse wird keine Mail verschickt.')
                    ->schema([
                        TextInput::make('mail_recipient')
                            ->label('Empfänger-Adresse')
                            ->email()
                            ->maxLength(255)
                            ->helperText('Leer lassen, um Mail-Reporting komplett zu deaktivieren.'),
                        Select::make('report_frequency')
                            ->label('Rhythmus der Zusammenfassung')
                            ->options([
                                'weekly' => 'Wöchentlich',
                                'monthly' => 'Monatlich',
                            ])
                            ->default('weekly')
                            ->required(),
                        TextInput::make('anomaly_threshold_percent')
                            ->label('Warn-Schwelle Tagesverbrauch (% über Durchschnitt)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(500)
                            ->suffix('%')
                            ->required(),
                        TextInput::make('anomaly_lookback_days')
                            ->label('Vergleichszeitraum für Durchschnitt (Tage)')
                            ->numeric()
                            ->minValue(3)
                            ->maxValue(90)
                            ->required(),
                        TextInput::make('sync_outage_threshold_hours')
                            ->label('Warn-Schwelle Sync-Ausfall (Stunden)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(168)
                            ->suffix('Std.')
                            ->required(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        Setting::set('meter_number', $data['meter_number']);
        Setting::set('forecast_horizon_days', $data['forecast_horizon_days']);
        Setting::set('mail_recipient', $data['mail_recipient']);
        Setting::set('report_frequency', $data['report_frequency']);
        Setting::set('anomaly_threshold_percent', $data['anomaly_threshold_percent']);
        Setting::set('anomaly_lookback_days', $data['anomaly_lookback_days']);
        Setting::set('sync_outage_threshold_hours', $data['sync_outage_threshold_hours']);
        Setting::set('device_status_sync_interval_hours', $data['device_status_sync_interval_hours']);

        $this->saveWeatherLocation($data['weather_location_name'] ?? null);

        Notification::make()
            ->title('Einstellungen gespeichert')
            ->success()
            ->send();

        // Formular aus dem tatsaechlich gespeicherten Stand neu befuellen -
        // falls das Geocoding fehlgeschlagen ist, bleibt der zuletzt
        // erfolgreich aufgeloeste Standort sichtbar statt des fehlerhaften
        // Eingabewerts.
        $this->fillForm();
    }

    /**
     * Ortsname per GeocodingService in Koordinaten aufloesen und beide
     * zusammen mit dem (ggf. praezisierten) Ortsnamen in den Settings
     * ablegen. Leerer Ortsname entfernt den Standort vollstaendig - die
     * davon abhaengigen Anzeigen blenden sich dann wieder aus (siehe
     * App\Filament\Pages\WeatherData::shouldRegisterNavigation()).
     */
    private function saveWeatherLocation(?string $locationName): void
    {
        $locationName = trim((string) $locationName) ?: null;
        $currentName = Setting::get('weather_location_name');

        if ($locationName === null) {
            if ($currentName !== null) {
                Setting::set('weather_location_name', null);
                Setting::set('weather_latitude', null);
                Setting::set('weather_longitude', null);
            }

            return;
        }

        if ($locationName === $currentName && Setting::get('weather_latitude') !== null) {
            // Unveraendert und bereits aufgeloest - kein erneuter Geocoding-Aufruf noetig.
            return;
        }

        try {
            $result = app(GeocodingService::class)->resolve($locationName);
        } catch (\Throwable $exception) {
            Notification::make()
                ->title('Standort konnte nicht ermittelt werden')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if (! $result) {
            Notification::make()
                ->title('Standort nicht gefunden')
                ->body("Für \"{$locationName}\" wurden keine Koordinaten gefunden. Bitte Schreibweise prüfen.")
                ->danger()
                ->send();

            return;
        }

        Setting::set('weather_location_name', $result['name']);
        Setting::set('weather_latitude', $result['latitude']);
        Setting::set('weather_longitude', $result['longitude']);
    }
}
