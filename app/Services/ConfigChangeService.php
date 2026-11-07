<?php

namespace App\Services;

use App\Models\Annotation;
use App\Models\ConfigLog;
use Illuminate\Support\Carbon;

/**
 * Protokolliert Aenderungen an der Anlagenkonfiguration (Heizkurve, Sollwerte,
 * Zeitprogramme, Betriebsarten, Ferienprogramm, §14a ...) ab Version 1.2.0.
 *
 * Arbeitet mit der Feature-Liste, die der Sync ohnehin abruft (ein Request
 * liefert alle Features), und verursacht dadurch keine zusaetzlichen
 * API-Anfragen. Pro Feature wird nur dann eine Zeile in config_log geschrieben,
 * wenn sich der Wert gegenueber der letzten Zeile geaendert hat. Beim ersten
 * Auftauchen eines Features entsteht ein Ausgangswert (is_baseline) ohne
 * Notiz. Bei jeder echten Aenderung wird zusaetzlich automatisch eine Notiz
 * (Kategorie "einstellung") angelegt, die im Dashboard-Widget erscheint.
 *
 * Der Zeitpunkt einer Aenderung ist nur auf das Sync-Intervall genau, da die
 * API fuer Konfigurations-Features keinen Aenderungszeitpunkt liefert.
 */
class ConfigChangeService
{
    public const ANNOTATION_CATEGORY = 'einstellung';

    /**
     * Protokollierte Features: Anzeigename sowie die zu vergleichenden
     * Eigenschaften (Property => Anzeigename). Rein dynamische Werte wie
     * "active" bei den Heizprogrammen (zeigt nur das gerade laufende
     * Programm) sind bewusst nicht enthalten.
     *
     * @var array<string, array{label: string, properties: array<string, string>}>
     */
    public const FEATURES = [
        'heating.circuits.0.heating.curve' => [
            'label' => 'Heizkurve',
            'properties' => ['slope' => 'Neigung', 'shift' => 'Niveau'],
        ],
        'heating.circuits.0.temperature.levels' => [
            'label' => 'Vorlauftemperatur-Grenzen',
            'properties' => ['min' => 'Minimum', 'max' => 'Maximum'],
        ],
        'heating.circuits.0.operating.programs.normalHeating' => [
            'label' => 'Soll-Raumtemperatur normal',
            'properties' => ['temperature' => 'Temperatur'],
        ],
        'heating.circuits.0.operating.programs.reducedHeating' => [
            'label' => 'Soll-Raumtemperatur reduziert',
            'properties' => ['temperature' => 'Temperatur'],
        ],
        'heating.circuits.0.operating.programs.comfortHeating' => [
            'label' => 'Soll-Raumtemperatur komfort',
            'properties' => ['temperature' => 'Temperatur'],
        ],
        'heating.circuits.0.heating.schedule' => [
            'label' => 'Zeitprogramm Heizen',
            'properties' => ['active' => 'Aktiv', 'entries' => 'Zeiten'],
        ],
        'heating.dhw.schedule' => [
            'label' => 'Zeitprogramm Warmwasser',
            'properties' => ['active' => 'Aktiv', 'entries' => 'Zeiten'],
        ],
        'heating.dhw.temperature.main' => [
            'label' => 'Warmwasser-Solltemperatur',
            'properties' => ['value' => 'Temperatur'],
        ],
        'heating.dhw.operating.modes.active' => [
            'label' => 'Betriebsart Warmwasser',
            'properties' => ['value' => 'Betriebsart'],
        ],
        'heating.dhw.temperature.hysteresis' => [
            'label' => 'Warmwasser-Hysterese',
            'properties' => ['switchOnValue' => 'Einschalten', 'switchOffValue' => 'Ausschalten'],
        ],
        'heating.dhw.hygiene' => [
            'label' => 'Warmwasser-Hygiene',
            'properties' => ['enabled' => 'Aktiviert'],
        ],
        'heating.circuits.0.configuration.summerEco.absolute' => [
            'label' => 'Sommerbetrieb (absolut)',
            'properties' => ['active' => 'Aktiv', 'threshold' => 'Schwelle'],
        ],
        'heating.circuits.0.operating.modes.active' => [
            'label' => 'Betriebsart Heizkreis',
            'properties' => ['value' => 'Betriebsart'],
        ],
        'heating.circuits.0.operating.modes.standby' => [
            'label' => 'Standby (Betriebsart)',
            'properties' => ['active' => 'Aktiv'],
        ],
        'heating.circuits.0.operating.programs.standby' => [
            'label' => 'Standby (Programm)',
            'properties' => ['active' => 'Aktiv'],
        ],
        'heating.operating.programs.holiday' => [
            'label' => 'Ferienprogramm',
            'properties' => ['active' => 'Aktiv', 'start' => 'Start', 'end' => 'Ende'],
        ],
        'heating.operating.programs.holidayAtHome' => [
            'label' => 'Ferienprogramm (zu Hause)',
            'properties' => ['active' => 'Aktiv', 'start' => 'Start', 'end' => 'Ende'],
        ],
        'device.power.consumption.limitation' => [
            'label' => 'Leistungsbegrenzung (§14a)',
            'properties' => ['value' => 'Status'],
        ],
        'device.parameterIdentification.version' => [
            'label' => 'Parameter-Version (Firmware)',
            'properties' => ['value' => 'Version'],
        ],
    ];

    private const UNITS = [
        'slope' => '',
        'shift' => '',
        'min' => ' °C',
        'max' => ' °C',
        'temperature' => ' °C',
        'switchOnValue' => ' K',
        'switchOffValue' => ' K',
        'threshold' => ' °C',
    ];

    private const VALUE_LABELS = [
        'heating' => 'Heizen',
        'standby' => 'Standby',
        'dhw' => 'Warmwasser',
        'dhwAndHeating' => 'Heizen und Warmwasser',
        'efficient' => 'Effizient',
        'efficientWithMinComfort' => 'Effizient mit Mindestkomfort',
        'comfort' => 'Komfort',
        'off' => 'Aus',
        'on' => 'Ein',
        'normal' => 'normal',
        'reduced' => 'reduziert',
        '14aOff' => '§14a aus',
        '14aOn' => '§14a aktiv',
    ];

    private const DAYS = [
        'mon' => 'Mo',
        'tue' => 'Di',
        'wed' => 'Mi',
        'thu' => 'Do',
        'fri' => 'Fr',
        'sat' => 'Sa',
        'sun' => 'So',
    ];

    /**
     * Vergleicht die protokollierten Features der uebergebenen Feature-Liste
     * mit dem jeweils letzten Eintrag in config_log.
     *
     * @param  array<int, array<string, mixed>>  $features  Antwort von ViessmannApiClient::listFeatures()
     * @return int Anzahl erkannter echter Aenderungen (ohne Ausgangswerte)
     */
    public function capture(array $features, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $byName = collect($features)->keyBy('feature');
        $changes = 0;

        foreach (self::FEATURES as $feature => $definition) {
            $properties = $byName->get($feature)['properties'] ?? null;

            if (! is_array($properties)) {
                continue;
            }

            $current = $this->extractValues($properties, array_keys($definition['properties']));

            if ($current === []) {
                continue;
            }

            $last = ConfigLog::query()
                ->where('feature', $feature)
                ->latest('timestamp')
                ->latest('id')
                ->first();

            if ($last === null) {
                ConfigLog::create([
                    'timestamp' => $now,
                    'feature' => $feature,
                    'value' => $current,
                    'previous_value' => null,
                    'is_baseline' => true,
                ]);

                continue;
            }

            $previous = $this->normalize($last->value ?? []);

            if ($previous === $current) {
                continue;
            }

            ConfigLog::create([
                'timestamp' => $now,
                'feature' => $feature,
                'value' => $current,
                'previous_value' => $previous,
                'is_baseline' => false,
            ]);

            Annotation::create([
                'timestamp' => $now,
                'note' => $this->buildNote($feature, $previous, $current),
                'category' => self::ANNOTATION_CATEGORY,
            ]);

            $changes++;
        }

        return $changes;
    }

    /**
     * Anzeigename eines Features (Fallback: technischer Name).
     */
    public static function label(string $feature): string
    {
        return self::FEATURES[$feature]['label'] ?? $feature;
    }

    /**
     * Gespeicherte Werte eines Features als lesbarer Text,
     * z. B. "Neigung 1,2, Niveau 0".
     *
     * @param  array<string, mixed>|null  $values
     */
    public static function formatValues(string $feature, ?array $values): string
    {
        if ($values === null || $values === []) {
            return '–';
        }

        $labels = self::FEATURES[$feature]['properties'] ?? [];
        $parts = [];

        foreach (self::orderedProperties($feature, $values) as $property => $value) {
            if ($property === 'entries') {
                $parts[] = self::formatSchedule(is_array($value) ? $value : []);

                continue;
            }

            $name = $labels[$property] ?? $property;
            $parts[] = count($values) > 1
                ? $name.' '.self::formatScalar((string) $property, $value)
                : self::formatScalar((string) $property, $value);
        }

        return implode(', ', $parts);
    }

    /**
     * Zeitprogramm kompakt: Tage mit identischen Zeiten werden zusammengefasst,
     * z. B. "Mo, Di, Mi: 06:00-22:00 normal; Sa: ...".
     *
     * @param  array<string, mixed>  $entries
     */
    public static function formatSchedule(array $entries): string
    {
        $groups = [];

        foreach (self::DAYS as $key => $short) {
            if (! isset($entries[$key]) || ! is_array($entries[$key])) {
                continue;
            }

            $slots = $entries[$key];

            $text = $slots === []
                ? 'keine Zeiten'
                : collect($slots)
                    ->map(fn ($slot): string => trim(
                        ($slot['start'] ?? '?').'-'.($slot['end'] ?? '?').' '.self::translate((string) ($slot['mode'] ?? ''))
                    ))
                    ->implode(', ');

            $groups[$text][] = $short;
        }

        if ($groups === []) {
            return '–';
        }

        return collect($groups)
            ->map(fn (array $days, string $text): string => implode(', ', $days).': '.$text)
            ->implode('; ');
    }

    /**
     * @param  array<string, mixed>  $properties  "properties"-Block eines Features
     * @param  array<int, string>  $names
     * @return array<string, mixed>
     */
    private function extractValues(array $properties, array $names): array
    {
        $values = [];

        foreach ($names as $name) {
            if (! array_key_exists($name, $properties)) {
                continue;
            }

            $property = $properties[$name];
            $values[$name] = is_array($property) && array_key_exists('value', $property)
                ? $property['value']
                : $property;
        }

        return $this->normalize($values);
    }

    /**
     * Vereinheitlicht Werte fuer den Vergleich: Schluessel sortiert (Maps),
     * ganzzahlige Floats als int (1.0 und 1 sind derselbe Wert).
     *
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function normalize(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->normalize($value);
            } elseif (is_float($value) && floor($value) === $value) {
                $values[$key] = (int) $value;
            }
        }

        if (! array_is_list($values)) {
            ksort($values);
        }

        return $values;
    }

    /**
     * Deutscher Notiztext, z. B.
     * "Heizkurve geändert: Neigung von 1,2 auf 1,0 (automatisch erfasst)".
     *
     * @param  array<string, mixed>  $previous
     * @param  array<string, mixed>  $current
     */
    private function buildNote(string $feature, array $previous, array $current): string
    {
        $labels = self::FEATURES[$feature]['properties'] ?? [];
        $changes = [];

        foreach (self::orderedProperties($feature, $current) as $property => $value) {
            $old = $previous[$property] ?? null;

            if ($old === $value) {
                continue;
            }

            if ($property === 'entries') {
                $changes[] = $this->describeScheduleChange(is_array($old) ? $old : [], is_array($value) ? $value : []);

                continue;
            }

            $name = $labels[$property] ?? $property;
            $changes[] = $name.' von '.self::formatScalar((string) $property, $old)
                .' auf '.self::formatScalar((string) $property, $value);
        }

        return self::label($feature).' geändert: '.implode(', ', $changes).' (automatisch erfasst)';
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function describeScheduleChange(array $old, array $new): string
    {
        $changedDays = [];

        foreach (self::DAYS as $key => $short) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                $changedDays[] = $short;
            }
        }

        return 'Zeiten angepasst ('.implode(', ', $changedDays).'), Details im Konfigurationsverlauf';
    }

    /**
     * Eigenschaften in der Reihenfolge der Feature-Definition (statt der
     * alphabetischen Reihenfolge, in der sie zum Vergleich normalisiert werden).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function orderedProperties(string $feature, array $values): array
    {
        $ordered = [];

        foreach (array_keys(self::FEATURES[$feature]['properties'] ?? []) as $property) {
            if (array_key_exists($property, $values)) {
                $ordered[$property] = $values[$property];
            }
        }

        return $ordered + $values;
    }

    private static function formatScalar(string $property, mixed $value): string
    {
        if ($value === null) {
            return '–';
        }

        if (is_bool($value)) {
            return $value ? 'ein' : 'aus';
        }

        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(number_format((float) $value, 2, ',', ''), '0'), ',').(self::UNITS[$property] ?? '');
        }

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return Carbon::parse($value)->format('d.m.Y');
        }

        return self::translate((string) $value);
    }

    private static function translate(string $value): string
    {
        return self::VALUE_LABELS[$value] ?? $value;
    }
}
