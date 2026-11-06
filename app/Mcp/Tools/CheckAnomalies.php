<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithToolInput;
use App\Models\ApiLog;
use App\Services\MailReportingService;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Nutzt dieselbe Bewertungslogik wie das Mail-Reporting
 * (MailReportingService::evaluateAnomaly()/evaluateSyncOutage(), inklusive
 * der in den Einstellungen hinterlegten Schwellenwerte), verschickt aber
 * keine Mail und schreibt keinen Debounce-Zustand.
 */
#[Name('check_anomalies')]
#[Title('Auffälligkeiten prüfen')]
#[Description('Prüft mit denselben Regeln und Schwellenwerten wie das Mail-Reporting, ob der Stromverbrauch des gestrigen Tages auffällig vom gleitenden Durchschnitt abweicht (nur Mehrverbrauch) und ob der Datenabruf (Sync) ausgefallen ist. Verschickt keine Mails und ändert nichts.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class CheckAnomalies extends Tool
{
    use InteractsWithToolInput;

    public function handle(Request $request, MailReportingService $reporting): Response|ResponseFactory
    {
        $now = now();

        $latestTimestamp = ApiLog::query()
            ->whereIn('source', ['scheduled', 'manual'])
            ->max('timestamp');

        $outage = $reporting->evaluateSyncOutage($now, $latestTimestamp ? Carbon::parse($latestTimestamp) : null);
        $anomaly = $reporting->evaluateAnomaly($now);

        $findings = [];

        if ($anomaly['status'] === 'anomaly') {
            $findings[] = sprintf(
                'Mehrverbrauch am %s: %s kWh gegenüber einem Durchschnitt von %s kWh (+%s %%, Schwellenwert %s %%, Vergleich über %d Tage).',
                $anomaly['date']->format('d.m.Y'),
                $this->de($anomaly['value_kwh']),
                $this->de($anomaly['average_kwh']),
                $this->de($anomaly['deviation_percent'], 1),
                $this->de($anomaly['threshold_percent'], 0),
                $anomaly['comparison_days'],
            );
        }

        if ($outage['status'] === 'outage') {
            $findings[] = sprintf(
                'Kein Datenabruf seit %d Stunden (letzter Eintrag %s, Schwellenwert %d Stunden).',
                $outage['gap_hours'],
                $outage['last_entry_at']->format('d.m.Y H:i'),
                $outage['threshold_hours'],
            );
        }

        $hasGaps = in_array($anomaly['status'], ['no_value', 'insufficient_data'], true)
            || $outage['status'] === 'no_data';

        $status = $findings !== [] ? 'attention' : ($hasGaps ? 'insufficient_data' : 'ok');

        return Response::structured([
            'checked_at' => $this->iso($now),
            'status' => $status,
            'findings' => $findings,
            'consumption_anomaly' => [
                'status' => $anomaly['status'],
                'date' => $anomaly['date']->toDateString(),
                'value_kwh' => $this->round($anomaly['value_kwh']),
                'average_kwh' => $this->round($anomaly['average_kwh']),
                'deviation_percent' => $this->round($anomaly['deviation_percent'], 1),
                'threshold_percent' => $anomaly['threshold_percent'],
                'lookback_days' => $anomaly['lookback_days'],
                'comparison_days' => $anomaly['comparison_days'],
            ],
            'sync_outage' => [
                'status' => $outage['status'],
                'last_entry_at' => $this->iso($outage['last_entry_at']),
                'gap_hours' => $outage['gap_hours'],
                'threshold_hours' => $outage['threshold_hours'],
            ],
            'status_legend' => [
                'consumption_anomaly.status' => 'ok = unauffällig, anomaly = Mehrverbrauch über Schwellenwert, insufficient_data = weniger als 3 Vergleichstage, no_value = kein Tageswert für gestern',
                'sync_outage.status' => 'ok = Daten aktuell, outage = Datenabruf überfällig, no_data = noch keine Daten',
            ],
        ]);
    }

    private function de(?float $value, int $decimals = 2): string
    {
        return $value === null ? '–' : number_format($value, $decimals, ',', '.');
    }
}
