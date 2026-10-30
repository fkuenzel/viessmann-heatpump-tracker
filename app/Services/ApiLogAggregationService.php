<?php

namespace App\Services;

use App\Models\ApiLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Loest die relative day_index-Struktur von api_log in absolute Kalendertage
 * auf und liefert Tageswerte fuer die Live-Berechnungen (Korrekturfaktor,
 * COP, Kosten).
 *
 * Power-Consumption-Werte (power_total_kwh/.heating/.dhw) sind ueber das
 * day[]-Array auch rueckwirkend verfuegbar (day_index 0-7, siehe Fahrplan
 * Abschnitt 4.2). Thermik/Aussentemperatur/Kompressor liefert die API nur
 * als Snapshot des laufenden Tages (Abschnitt 4.10) - fuer vergangene Tage
 * gibt es dafuer kein day[]-Array. Sie lassen sich aber ueber die
 * "Anlagenwerte"-Oberflaeche auf JEDER bestehenden api_log-Zeile nachtragen,
 * unabhaengig vom day_index (Abschnitt 4.12/4.17) - dailyPowerValue() und
 * lastSnapshotValue() loesen deshalb beide ueber dieselbe Referenzdatum-Logik
 * (timestamp minus day_index Tage) auf, nicht ueber day_index=0 allein.
 */
class ApiLogAggregationService
{
    /**
     * Tageswert einer Power-Consumption-Spalte fuer ein konkretes Kalenderdatum.
     * Nimmt den zuletzt gepullten Wert, der ueber day_index auf dieses Datum
     * zeigt (day_index-Werte fuer vergangene Tage aendern sich nicht mehr,
     * die Auswahl "zuletzt gepullt" ist daher nur fuer day_index 0 relevant).
     */
    public function dailyPowerValue(Carbon $date, string $column): ?float
    {
        return $this->resolvedValueForDate($date, $column);
    }

    /**
     * Wert einer Snapshot-Spalte (thermal_heating_kwh/thermal_dhw_kwh/
     * outdoor_temp/compressor_*) fuer ein Kalenderdatum - diese Spalten haben
     * kein day[]-Array in der API, werden aber ueber die "Anlagenwerte"-
     * Oberflaeche (Abschnitt 4.12) nachtraeglich auf JEDER api_log-Zeile
     * befuellt, unabhaengig vom day_index (Fabian traegt z. B. die Thermik
     * fuer einen vergangenen Tag direkt in dessen bestehende day_index>0-Zeile
     * nach, statt eine neue day_index=0-Zeile anzulegen). Frueher war diese
     * Methode faelschlich auf day_index=0 beschraenkt, wodurch nachgetragene
     * Werte fuer vergangene Tage im Dashboard nicht erschienen (Fabian
     * 29.10.2026 - Dashboard-Review Teil 2). Nutzt jetzt dieselbe
     * Referenzdatum-Aufloesung (timestamp minus day_index Tage) wie
     * dailyPowerValue(), statt sich auf day_index=0 zu beschraenken.
     */
    public function lastSnapshotValue(Carbon $date, string $column): ?float
    {
        return $this->resolvedValueForDate($date, $column);
    }

    /**
     * Gemeinsame Aufloesungslogik fuer dailyPowerValue() und
     * lastSnapshotValue(): sucht unter allen Zeilen, deren Referenzdatum
     * (timestamp minus day_index Tage) auf $date zeigt, die zuletzt gepullte
     * mit einem Wert in $column.
     */
    private function resolvedValueForDate(Carbon $date, string $column): ?float
    {
        $match = $this->resolvedRowForDate($date, $column);

        return $match ? (float) $match->{$column} : null;
    }

    /**
     * Sync-Zeitpunkt (nicht Messdatum) der Zeile, die resolvedValueForDate()
     * fuer ein Kalenderdatum/Spalte findet - fuer die "Stand HH:mm"-Anzeige
     * in WeatherWidget (Fahrplan Abschnitt 4.30, Fabians Wunsch: erkennen,
     * wie aktuell ein angezeigter Wert ist).
     */
    public function lastSnapshotTimestamp(Carbon $date, string $column): ?Carbon
    {
        return $this->resolvedRowForDate($date, $column)?->timestamp;
    }

    private function resolvedRowForDate(Carbon $date, string $column): ?ApiLog
    {
        $date = $date->copy()->startOfDay();

        $rows = ApiLog::query()
            ->whereBetween('timestamp', [$date, $date->copy()->addDays(8)])
            ->whereNotNull($column)
            ->orderByDesc('timestamp')
            ->get(['timestamp', 'day_index', $column]);

        return $rows->first(
            fn (ApiLog $row) => $row->timestamp->copy()->startOfDay()->subDays($row->day_index)->equalTo($date)
        );
    }

    /**
     * Gewichtete Summe einer Power-Consumption-Spalte ueber ein Zeitintervall,
     * gemaess der in Phase 0 festgelegten proportionalen Rand-Intervall-Methode:
     * Grenztage werden nach Tagesanteil gewichtet, Tage dazwischen voll gezaehlt.
     *
     * @return array{value: ?float, missing_dates: array<string>}
     */
    public function weightedSum(Carbon $from, Carbon $to, string $column): array
    {
        $sum = 0.0;
        $missingDates = [];
        $hasAnyValue = false;

        $cursor = $from->copy()->startOfDay();
        $lastDay = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $dayValue = $this->dailyPowerValue($cursor, $column);

            if ($dayValue === null) {
                $missingDates[] = $cursor->toDateString();
                $cursor->addDay();

                continue;
            }

            $hasAnyValue = true;
            $fraction = $this->dayFraction($cursor, $from, $to);
            $sum += $dayValue * $fraction;

            $cursor->addDay();
        }

        return [
            'value' => $hasAnyValue ? $sum : null,
            'missing_dates' => $missingDates,
        ];
    }

    /**
     * Anteil eines Kalendertags, der innerhalb des [from, to]-Intervalls liegt
     * (proportionale Rand-Intervall-Methode aus Fahrplan Abschnitt 6).
     */
    private function dayFraction(Carbon $day, Carbon $from, Carbon $to): float
    {
        $dayStart = $day->copy()->startOfDay();
        $dayEnd = $day->copy()->endOfDay()->addSecond(); // exklusive Grenze zum naechsten Tag

        $windowStart = $from->greaterThan($dayStart) ? $from : $dayStart;
        $windowEnd = $to->lessThan($dayEnd) ? $to : $dayEnd;

        if ($windowEnd->lessThanOrEqualTo($windowStart)) {
            return 0.0;
        }

        return $windowStart->diffInSeconds($windowEnd) / 86400;
    }

    /**
     * Frueheste Kalendertag-Referenz (Messdatum), an dem mindestens eine der
     * angegebenen Spalten einen Wert hat. Nutzt dieselbe date()-Umrechnung
     * wie das ApiLogResource-Sorting (timestamp minus day_index Tage), damit
     * Charts ihren Darstellungszeitraum an die tatsaechlich vorhandenen
     * Daten anpassen koennen, statt immer starr 7/30/90 Tage zurueckzugehen
     * (Fabian, 29.10.2026 - Dashboard-Review).
     *
     * @param array<string> $columns
     */
    public function earliestReferenceDate(array $columns): ?Carbon
    {
        $query = ApiLog::query()->where(function ($q) use ($columns) {
            foreach ($columns as $column) {
                $q->orWhereNotNull($column);
            }
        });

        $min = $query->selectRaw("MIN(date(timestamp, '-' || day_index || ' days')) as min_date")
            ->value('min_date');

        return $min ? Carbon::parse($min)->startOfDay() : null;
    }

    /**
     * Durchschnittliche Aussentemperatur an einem Kalendertag aus den
     * vorhandenen Snapshot-Pulls (einfacher Mittelwert, keine Gewichtung
     * nach Pull-Abstand).
     */
    public function averageOutdoorTemp(Carbon $date): ?float
    {
        $date = $date->copy()->startOfDay();

        $values = ApiLog::query()
            ->where('day_index', 0)
            ->whereBetween('timestamp', [$date, $date->copy()->endOfDay()])
            ->whereNotNull('outdoor_temp')
            ->pluck('outdoor_temp');

        return $values->isEmpty() ? null : (float) $values->avg();
    }
}
