<?php

namespace App\Filament\Pages;

use App\Services\ViessmannApiClient;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * Live-Geraetestatus analog zur ViCare-App-Seite "Geraetestatus" (Fabians
 * Wunsch, 30.10.2026, Screenshot aus der App). Fragt die Werte direkt per
 * API ab (ViessmannApiClient::getDeviceStatus()), nicht aus api_log -
 * dadurch immer der aktuelle Live-Stand, unabhaengig vom letzten
 * Scheduler-Sync. Die Historisierung derselben Werte laeuft parallel ueber
 * ViessmannSyncService in api_log (siehe dortigen Docblock sowie Migration
 * 2026_10_30_180000_add_device_status_fields_to_api_log_table) - fuer
 * spaetere Trend-Charts, hier geht es nur um den aktuellen Momentanwert.
 *
 * Gruppierung (Waermeerzeuger/Sekundaerkreis/Verdichter) uebernimmt die
 * Struktur aus Fabians Screenshot der ViCare-App 1:1, damit sich die Seite
 * sofort vertraut anfuehlt.
 *
 * $lastUpdated (Fabians Wunsch, 31.10.2026): da die Werte live bei jedem
 * Seitenaufruf/Klick auf "Aktualisieren" neu von der API geholt werden,
 * muss sichtbar sein, WANN genau das zuletzt erfolgreich passiert ist -
 * sonst wirkt die Seite wie eine fortlaufend aktuelle Live-Anzeige, obwohl
 * sie den Stand des letzten Abrufs zeigt.
 */
class DeviceStatus extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?string $navigationLabel = 'Gerätestatus';

    protected static \UnitEnum | string | null $navigationGroup = 'Daten erfassen';

    protected static ?string $title = 'Gerätestatus';

    protected string $view = 'filament.pages.device-status';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $status = null;

    public bool $connected = false;

    public ?string $error = null;

    public ?Carbon $lastUpdated = null;

    public function mount(): void
    {
        $this->loadStatus();
    }

    public function loadStatus(): void
    {
        $client = app(ViessmannApiClient::class);
        $this->connected = $client->isConnected();
        $this->error = null;

        if (! $this->connected) {
            $this->status = null;

            return;
        }

        try {
            $this->status = $client->getDeviceStatus();
            $this->lastUpdated = now();
        } catch (\Throwable $exception) {
            $this->status = null;
            $this->error = $exception->getMessage();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Aktualisieren')
                ->icon('heroicon-o-arrow-path')
                ->action('loadStatus'),
        ];
    }

    /**
     * @return array<int, array{label: string, description: string, value: string, badge: ?string}>
     */
    public function getWaermeerzeugerRows(): array
    {
        return [
            $this->row('Vorlauftemperatur', 'Ist-Temperatur im Vorlauf', $this->formatTemperature($this->status['flow_temp'] ?? null)),
            $this->row('Rücklauftemperatur', 'Ist-Temperatur im Rücklauf', $this->formatTemperature($this->status['return_temp'] ?? null)),
            $this->row('Anlagendruck', 'Der Druck sollte 1.0 bar nicht unterschreiten', $this->formatPressure($this->status['system_pressure'] ?? null)),
            $this->onOffRow('Interne Pumpe', 'Zeigt den Status der internen Pumpe', $this->isOn($this->status['internal_pump_status'] ?? null)),
            $this->row('Volumenstrom', 'Volumenstrom', $this->formatFlow($this->status['volumetric_flow'] ?? null)),
        ];
    }

    /**
     * @return array<int, array{label: string, description: string, value: string, badge: ?string}>
     */
    public function getSekundaerkreisRows(): array
    {
        return [
            $this->row('Vorlauftemperatur Sekundärkreis', 'Ist-Temperatur im Sekundärkreis', $this->formatTemperature($this->status['secondary_flow_temp'] ?? null)),
        ];
    }

    /**
     * @return array<int, array{label: string, description: string, value: string, badge: ?string}>
     */
    public function getVerdichterRows(): array
    {
        $active = $this->status['compressor_active'] ?? null;
        $phase = $this->status['compressor_phase'] ?? null;
        $hours = $this->status['compressor_hours'] ?? null;
        $starts = $this->status['compressor_starts'] ?? null;

        return [
            $this->onOffRow('Verdichterstatus', 'Aktueller Status des Verdichters', $active),
            $this->row('Verdichter-Phase', 'Roh-Status aus der API', $phase ?? '–'),
            $this->row('Betriebsstunden Verdichter', 'Kumuliert seit Inbetriebnahme', $hours === null ? '–' : number_format($hours, 0, ',', '.') . ' h'),
            $this->row('Kompressorstarts', 'Kumuliert seit Inbetriebnahme', $starts === null ? '–' : number_format($starts, 0, ',', '.')),
        ];
    }

    /**
     * Normale Zeile ohne Farb-Badge (Messwerte wie Temperatur, Druck,
     * Volumenstrom).
     *
     * @return array{label: string, description: string, value: string, badge: ?string}
     */
    private function row(string $label, string $description, string $value): array
    {
        return ['label' => $label, 'description' => $description, 'value' => $value, 'badge' => null];
    }

    /**
     * EIN/AUS-Zeile mit Farb-Badge (Fabians Wunsch, 30.10.2026): EIN gruen,
     * AUS rot, unbekannter Status grau. $on ist null, wenn der Wert (noch)
     * nicht abgerufen werden konnte.
     *
     * @return array{label: string, description: string, value: string, badge: ?string}
     */
    private function onOffRow(string $label, string $description, ?bool $on): array
    {
        return [
            'label' => $label,
            'description' => $description,
            'value' => $on === null ? '–' : ($on ? 'EIN' : 'AUS'),
            'badge' => $on === null ? 'gray' : ($on ? 'success' : 'danger'),
        ];
    }

    private function isOn(?string $status): ?bool
    {
        return match ($status) {
            'on' => true,
            'off' => false,
            default => null,
        };
    }

    private function formatTemperature(?float $value): string
    {
        return $value === null ? '–' : number_format($value, 1, ',', '.') . ' °C';
    }

    private function formatPressure(?float $value): string
    {
        return $value === null ? '–' : number_format($value, 1, ',', '.') . ' bar';
    }

    private function formatFlow(?float $value): string
    {
        return $value === null ? '–' : number_format($value, 0, ',', '.') . ' l/h';
    }

}
