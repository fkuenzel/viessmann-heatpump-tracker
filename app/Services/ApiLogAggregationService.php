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
        $raw = $this->resolvedValueForDate($date, $column);

        if ($column !== 'power_total_kwh') {
            return $raw;
        }

        // Die API liefert den Gesamtwert teils kleiner als Heizen + Warmwasser
        // (beobachtet an 4 von 36 Tagen, z. B. 04.11.2026: 22,7 < 21,7 + 2,1).
        // Ein Gesamtverbrauch unterhalb der Teilsummen ist physikalisch
        // unmoeglich - daher gilt der groessere Wert. Die gespeicherten
        // Rohwerte bleiben unveraendert (siehe rawDailyValue()).
        $heating = $this->resolvedValueForDate($date, 'power_heating_kwh');
        $dhw = $this->resolvedValueForDate($date, 'power_dhw_kwh');

        if ($heating === null && $dhw === null) {
            return $raw;
        }

        $partsSum = ($heating ?? 0.0) + ($dhw ?? 0.0);

        return $raw === null ? $partsSum : max($raw, $partsSum);
    }

    /**
     * Unkorrigierter, so gespeicherter Tageswert einer Spalte. Fuer die
     * Aenderungserkennung beim Sync, damit der Gesamtwert-Fallback aus
     * dailyPowerValue() keine Rohdaten-Vergleiche verfaelscht.
     */
    public function rawDailyValue(Carbon $date, string $column): ?float
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

    /**
     * Spalten, die der Aggregationsservice aus api_log liest. Alle Tageswerte
     * eines Requests werden aus einem einmal geladenen, im Speicher nach
     * Referenzdatum indizierten Abzug aufgeloest (statt je Tag und Spalte eine
     * eigene Abfrage - ein 30-Tage-Bericht brauchte vorher ueber 1.000
     * Abfragen). Der Index wird beim Speichern/Loeschen eines ApiLog-Eintrags
     * verworfen (siehe ApiLog::booted()).
     */
    private const INDEXED_COLUMNS = [
        'power_total_kwh', 'power_heating_kwh', 'power_dhw_kwh',
        'thermal_heating_kwh', 'thermal_dhw_kwh', 'outdoor_temp',
        'compressor_starts', 'compressor_hours',
        'heating_rod_heating_kwh', 'heating_rod_dhw_kwh', 'heating_rod_starts', 'heating_rod_hours',
    ];

    /** @var array<string, array<int, \stdClass>>|null Referenzdatum => Zeilen, neueste zuerst */
    private ?array $index = null;

    public function flush(): void
    {
        $this->index = null;
    }

    /**
     * @return array<string, array<int, \stdClass>>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $existing = \Illuminate\Support\Facades\Schema::getColumnListing('api_log');
        $columns = array_values(array_intersect(self::INDEXED_COLUMNS, $existing));

        $rows = \Illuminate\Support\Facades\DB::table('api_log')
            ->orderByDesc('timestamp')
            ->orderByDesc('id') // deterministisch bei identischem Zeitstempel
            ->get(['timestamp', 'day_index', ...$columns]);

        $index = [];

        foreach ($rows as $row) {
            $timestamp = Carbon::parse($row->timestamp);
            $row->timestamp = $timestamp;
            $row->day_index = (int) $row->day_index;
            $reference = $timestamp->copy()->startOfDay()->subDays($row->day_index)->toDateString();
            $index[$reference][] = $row;
        }

        return $this->index = $index;
    }

    /**
     * @return \stdClass|null Zeile mit timestamp (Carbon), day_index und den Spaltenwerten
     */
    private function resolvedRowForDate(Carbon $date, string $column): ?\stdClass
    {
        foreach ($this->index()[$date->toDateString()] ?? [] as $row) {
            if (($row->{$column} ?? null) !== null) {
                return $row;
            }
        }

        return null;
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
        return $this->weightedAggregate(
            $from,
            $to,
            fn (Carbon $day): ?float => $this->dailyPowerValue($day, $column),
            [$column],
        );
    }

    /**
     * Thermische Energie (Heizen + Warmwasser) ueber ein Zeitintervall mit
     * derselben Randgewichtung wie weightedSum(). Frueher wurden Randtage
     * der Thermik voll, der Strom aber anteilig gezaehlt - das verzerrte den
     * COP bei kurzen Intervallen (z. B. App-COP 7,0 statt rund 4,6).
     *
     * @return array{value: ?float, missing_dates: array<string>}
     */
    public function weightedThermalSum(Carbon $from, Carbon $to): array
    {
        return $this->weightedAggregate(
            $from,
            $to,
            function (Carbon $day): ?float {
                $heating = $this->lastSnapshotValue($day, 'thermal_heating_kwh');
                $dhw = $this->lastSnapshotValue($day, 'thermal_dhw_kwh');

                return ($heating === null && $dhw === null) ? null : ($heating ?? 0.0) + ($dhw ?? 0.0);
            },
            ['thermal_heating_kwh', 'thermal_dhw_kwh'],
        );
    }

    /**
     * @param  callable(Carbon): ?float  $dayValue
     * @param  array<string>  $coverageColumns Spalten, anhand derer die Abdeckung des laufenden Tages bestimmt wird
     * @return array{value: ?float, missing_dates: array<string>}
     */
    private function weightedAggregate(Carbon $from, Carbon $to, callable $dayValue, array $coverageColumns): array
    {
        $sum = 0.0;
        $missingDates = [];
        $hasAnyValue = false;

        $cursor = $from->copy()->startOfDay();
        $lastDay = $to->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($lastDay)) {
            $value = $dayValue($cursor);

            if ($value === null) {
                $missingDates[] = $cursor->toDateString();
                $cursor->addDay();

                continue;
            }

            $hasAnyValue = true;
            $sum += $value * $this->dayFraction($cursor, $from, $to, $this->coverageEnd($cursor, $coverageColumns));

            $cursor->addDay();
        }

        return [
            'value' => $hasAnyValue ? $sum : null,
            'missing_dates' => $missingDates,
        ];
    }

    /**
     * Bis wann ein Tageswert tatsaechlich Daten enthaelt. Ein Wert, der am
     * selben Tag noch als laufender Tag (day_index 0) gepullt wurde, deckt nur
     * 00:00 Uhr bis zum Pull-Zeitpunkt ab. Abgeschlossene Tage liefern null
     * (= ganzer Tag).
     *
     * @param  array<string>  $columns
     */
    private function coverageEnd(Carbon $day, array $columns): ?Carbon
    {
        $dayStart = $day->copy()->startOfDay();

        foreach ($columns as $column) {
            $row = $this->resolvedRowForDate($dayStart, $column);

            if ($row === null) {
                continue;
            }

            if ($row->day_index === 0 && $row->timestamp->lessThan($dayStart->copy()->addDay())) {
                return $row->timestamp;
            }

            return null;
        }

        return null;
    }

    /**
     * Anteil des Tageswerts, der auf das [from, to]-Intervall entfaellt. Bei
     * einem laufenden Tag (coverageEnd gesetzt) bezieht sich der Wert nur auf
     * den Zeitraum bis coverageEnd, nicht auf 24 Stunden.
     */
    private function dayFraction(Carbon $day, Carbon $from, Carbon $to, ?Carbon $coverageEnd = null): float
    {
        $dayStart = $day->copy()->startOfDay();
        $dayEnd = $dayStart->copy()->addDay(); // exklusive Grenze zum naechsten Tag

        $spanEnd = ($coverageEnd !== null && $coverageEnd->lessThan($dayEnd)) ? $coverageEnd : $dayEnd;

        $windowStart = $from->greaterThan($dayStart) ? $from : $dayStart;
        $windowEnd = $to->lessThan($spanEnd) ? $to : $spanEnd;

        $spanSeconds = $dayStart->diffInSeconds($spanEnd);

        if ($spanSeconds <= 0 || $windowEnd->lessThanOrEqualTo($windowStart)) {
            return 0.0;
        }

        return $windowStart->diffInSeconds($windowEnd) / $spanSeconds;
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
        $values = collect($this->index()[$date->toDateString()] ?? [])
            ->filter(fn (\stdClass $row): bool => $row->day_index === 0 && $row->outdoor_temp !== null
                && $row->timestamp->isSameDay($date))
            ->pluck('outdoor_temp');

        return $values->isEmpty() ? null : (float) $values->avg();
    }
}
