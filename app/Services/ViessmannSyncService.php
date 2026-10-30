<?php

namespace App\Services;

use App\Models\ApiLog;
use Illuminate\Support\Carbon;

/**
 * Holt die aktuellen Consumption-/Production-Werte von der Viessmann-API
 * und schreibt sie als Rohdaten in api_log.
 *
 * Nur heating.power.consumption.* liefert ein day[]-Array (Index 0 = heute,
 * nicht final, bis Index 7 = Nachhol-Funktion fuer verpasste Tage). Thermische
 * Produktion (heat.production.summary), Aussentemperatur und Kompressor-
 * Statistik liefern nur einen aktuellen Snapshot (kein day[]-Array) und
 * werden deshalb ausschliesslich bei day_index 0 mitgeschrieben.
 */
class ViessmannSyncService
{
    /**
     * Anzahl Tage, die im day[]-Array der power.consumption-Features
     * zurueckliegen (0 = heute, bestaetigt in Abschnitt 3 des Fahrplans).
     */
    private const DAY_ARRAY_LENGTH = 8;

    public function __construct(
        private readonly ViessmannApiClient $client,
        private readonly ApiLogAggregationService $aggregation,
        private readonly MailReportingService $mailReporting,
    ) {
    }

    /**
     * @return int Anzahl geschriebener api_log-Zeilen
     */
    public function sync(string $source = 'scheduled'): int
    {
        $now = Carbon::now();

        // Fuer die Sync-Ausfall-Warnung (Mail-Reporting, Fahrplan Abschnitt 4.19/
        // Phase 6): Zeitstempel der zuletzt vorhandenen Zeile VOR diesem
        // Sync-Lauf festhalten, bevor unten neue Zeilen geschrieben werden.
        $latestLogTimestampBeforeSync = ApiLog::query()->max('timestamp');
        $latestLogTimestampBeforeSync = $latestLogTimestampBeforeSync
            ? Carbon::parse($latestLogTimestampBeforeSync)
            : null;

        $powerTotal = $this->dayArray('heating.power.consumption.total');
        $powerHeating = $this->dayArray('heating.power.consumption.heating');
        $powerDhw = $this->dayArray('heating.power.consumption.dhw');

        $thermalHeating = $this->currentDayValue('heating.heat.production.summary.heating');
        $thermalDhw = $this->currentDayValue('heating.heat.production.summary.dhw');
        $outdoorTemp = $this->sensorValue('heating.sensors.temperature.outside');
        $compressor = $this->compressorStatistics();

        $written = 0;

        for ($dayIndex = 0; $dayIndex < self::DAY_ARRAY_LENGTH; $dayIndex++) {
            if (! array_key_exists($dayIndex, $powerTotal)
                && ! array_key_exists($dayIndex, $powerHeating)
                && ! array_key_exists($dayIndex, $powerDhw)) {
                continue;
            }

            $newTotal = $powerTotal[$dayIndex] ?? null;
            $newHeating = $powerHeating[$dayIndex] ?? null;
            $newDhw = $powerDhw[$dayIndex] ?? null;
            $resolvedDate = $now->copy()->startOfDay()->subDays($dayIndex);

            // Abgeschlossene Tage (day_index > 0) aendern sich normalerweise nicht mehr.
            // Nur schreiben, wenn sich der Wert gegenueber der zuletzt gespeicherten
            // Zeile fuer diesen Kalendertag tatsaechlich geaendert hat (z. B. eine
            // nachtraegliche Korrektur der API) - sonst unnoetige Datensammelei
            // (Fabians Einwand, 28.10.2026). day_index = 0 (heute) wird immer
            // geschrieben, da sich der laufende Tageswert bewusst noch aendert.
            if ($dayIndex > 0) {
                $unchanged = $this->valuesUnchanged($resolvedDate, [
                    'power_total_kwh' => $newTotal,
                    'power_heating_kwh' => $newHeating,
                    'power_dhw_kwh' => $newDhw,
                ]);

                if ($unchanged) {
                    continue;
                }
            }

            // Messuhrzeit (Fabian, 29.10.2026): dayValueReadAt der API wurde als
            // Messuhrzeit fuer day_index=0 verworfen - es spiegelt nicht die
            // Aktualitaet von day[0] wider, sondern einen gemeinsamen Zeitstempel
            // fuer die periodische Aggregation von Tag/Woche/Monat/Jahr (belegt
            // am 29.10.2026: day[0] war bereits live auf dem aktuellen Stand,
            // waehrend dayValueReadAt/weekValueReadAt/monthValueReadAt/
            // yearValueReadAt alle identisch auf dem Vortag standen). Fuer
            // day_index=0 daher der Sync-Pull-Zeitpunkt selbst. Fuer
            // abgeschlossene Tage weiterhin 00:00 Uhr des Messdatums - Konvention
            // fuer "ganztaegige Messung", angezeigt als "24 Std. Messung".
            $measuredAt = $dayIndex === 0
                ? $now
                : $resolvedDate->copy()->startOfDay();

            ApiLog::create([
                'timestamp' => $now,
                'source' => $source,
                'day_index' => $dayIndex,
                'measured_at' => $measuredAt,
                'power_total_kwh' => $newTotal,
                'power_heating_kwh' => $newHeating,
                'power_dhw_kwh' => $newDhw,
                'thermal_heating_kwh' => $dayIndex === 0 ? $thermalHeating : null,
                'thermal_dhw_kwh' => $dayIndex === 0 ? $thermalDhw : null,
                'outdoor_temp' => $dayIndex === 0 ? $outdoorTemp : null,
                'compressor_starts' => $dayIndex === 0 ? ($compressor['starts'] ?? null) : null,
                'compressor_hours' => $dayIndex === 0 ? ($compressor['hours'] ?? null) : null,
            ]);

            $written++;
        }

        // Mail-Reporting (Fahrplan Phase 6, Fabian 29.10.2026): laeuft bei
        // jedem Sync mit, egal ob Scheduler oder manuell ausgeloest - die App
        // laeuft nicht dauerhaft (Abschnitt 0), ein separater Zeitplan wuerde
        // regelmaessig verpasst. Ohne hinterlegten Empfaenger in den
        // Einstellungen passiert dort nichts.
        $this->mailReporting->checkAndNotify($now, $latestLogTimestampBeforeSync);

        return $written;
    }

    /**
     * Prueft, ob die zuletzt gespeicherten Werte fuer ein Kalenderdatum mit
     * den neu abgerufenen Werten uebereinstimmen (auf 2 Nachkommastellen
     * gerundet, um Float-Rundungsrauschen zu ignorieren). Gibt false zurueck,
     * wenn es noch keine Zeile fuer dieses Datum gibt - dann wird geschrieben.
     *
     * @param  array<string, ?float>  $newValues  Spalte => neuer Wert
     */
    private function valuesUnchanged(Carbon $resolvedDate, array $newValues): bool
    {
        foreach ($newValues as $column => $newValue) {
            $existing = $this->aggregation->dailyPowerValue($resolvedDate, $column);

            if ($existing === null && $newValue === null) {
                continue;
            }

            if ($existing === null || $newValue === null) {
                return false;
            }

            if (round($existing, 2) !== round($newValue, 2)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, float> day_index => Wert in kWh
     */
    private function dayArray(string $featureName): array
    {
        $properties = $this->client->getFeature($featureName)['properties'] ?? [];
        $values = $properties['day']['value'] ?? [];

        return array_map(static fn ($value) => (float) $value, $values);
    }

    private function currentDayValue(string $featureName): ?float
    {
        $properties = $this->client->getFeature($featureName)['properties'] ?? [];
        $value = $properties['currentDay']['value'] ?? null;

        return $value === null ? null : (float) $value;
    }

    private function sensorValue(string $featureName): ?float
    {
        $properties = $this->client->getFeature($featureName)['properties'] ?? [];
        $value = $properties['value']['value'] ?? null;

        return $value === null ? null : (float) $value;
    }

    /**
     * @return array{starts: ?int, hours: ?float}
     */
    private function compressorStatistics(): array
    {
        $properties = $this->client->getFeature('heating.compressors.0.statistics')['properties'] ?? [];

        $starts = $properties['starts']['value'] ?? null;
        $hours = $properties['hours']['value'] ?? null;

        return [
            'starts' => $starts === null ? null : (int) $starts,
            'hours' => $hours === null ? null : (float) $hours,
        ];
    }
}
