<?php

namespace App\Filament\Pages;

use App\Models\Setting;
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
        $this->form->fill([
            'meter_number' => Setting::get('meter_number'),
            'forecast_horizon_days' => (int) Setting::get('forecast_horizon_days', 3),
            'mail_recipient' => Setting::get('mail_recipient'),
            'report_frequency' => Setting::get('report_frequency', 'weekly'),
            'anomaly_threshold_percent' => (float) Setting::get('anomaly_threshold_percent', 30),
            'anomaly_lookback_days' => (int) Setting::get('anomaly_lookback_days', 14),
            'sync_outage_threshold_hours' => (int) Setting::get('sync_outage_threshold_hours', 24),
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

        Notification::make()
            ->title('Einstellungen gespeichert')
            ->success()
            ->send();
    }
}
