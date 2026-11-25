<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Auswertungszeitraum samt optionalem Vergleichszeitraum (Release 2.0.0).
 * Gemeinsame Grundlage fuer Dashboard-Filter, PeriodReportService, die
 * Auswertungsseiten und den MCP-Server.
 *
 * "from" und "to" sind inklusive Kalendertage (jeweils 00:00 Uhr).
 */
final class ReportPeriod
{
    public const PRESETS = [
        'last_7' => 'Letzte 7 Tage',
        'last_30' => 'Letzte 30 Tage',
        'last_90' => 'Letzte 90 Tage',
        'this_month' => 'Dieser Monat',
        'last_month' => 'Letzter Monat',
        'this_year' => 'Dieses Jahr',
        'last_year' => 'Letztes Jahr',
        'all' => 'Gesamter Zeitraum',
        'custom' => 'Benutzerdefiniert',
    ];

    public const COMPARE_MODES = [
        'previous' => 'Vorheriger Zeitraum',
        'previous_year' => 'Vorjahreszeitraum',
        'none' => 'Kein Vergleich',
    ];

    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly string $compare = 'previous',
        public readonly string $preset = 'custom',
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $filters  Dashboard-Filter (preset, from, to, compare)
     */
    public static function fromFilters(?array $filters, ?Carbon $now = null, ?Carbon $earliest = null): self
    {
        $filters ??= [];
        $compare = array_key_exists((string) ($filters['compare'] ?? ''), self::COMPARE_MODES)
            ? (string) $filters['compare']
            : 'previous';

        $preset = (string) ($filters['preset'] ?? '');

        if (! array_key_exists($preset, self::PRESETS)) {
            $preset = 'last_30';
        }

        return self::forPreset($preset, $filters['from'] ?? null, $filters['to'] ?? null, $compare, $now, $earliest);
    }

    public static function forPreset(string $preset, ?string $from = null, ?string $to = null, string $compare = 'previous', ?Carbon $now = null, ?Carbon $earliest = null): self
    {
        $today = ($now ?? Carbon::now())->copy()->startOfDay();

        [$start, $end] = match ($preset) {
            'last_7' => [$today->copy()->subDays(7), $today->copy()->subDay()],
            'last_90' => [$today->copy()->subDays(90), $today->copy()->subDay()],
            'this_month' => [$today->copy()->startOfMonth(), $today],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_year' => [$today->copy()->startOfYear(), $today],
            'last_year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()->startOfDay()],
            'all' => [($earliest ?? $today->copy()->subDays(30))->copy()->startOfDay(), $today],
            'custom' => [
                $from ? Carbon::parse($from)->startOfDay() : $today->copy()->subDays(30),
                $to ? Carbon::parse($to)->startOfDay() : $today->copy()->subDay(),
            ],
            default => [$today->copy()->subDays(30), $today->copy()->subDay()],
        };

        if ($end->lessThan($start)) {
            [$start, $end] = [$end, $start];
        }

        return new self($start, $end, $compare, $preset);
    }

    public function dayCount(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function includesToday(?Carbon $now = null): bool
    {
        return $this->to->greaterThanOrEqualTo(($now ?? Carbon::now())->copy()->startOfDay());
    }

    /**
     * Letzter vollstaendig abgeschlossener Tag des Zeitraums (fuer Linien-Charts;
     * der laufende Tag wird dort getrennt als eigener Punkt gezeigt).
     */
    public function chartTo(?Carbon $now = null): Carbon
    {
        $yesterday = ($now ?? Carbon::now())->copy()->startOfDay()->subDay();

        return $this->to->lessThan($yesterday) ? $this->to->copy() : $yesterday;
    }

    public function hasComparison(): bool
    {
        return $this->compare !== 'none';
    }

    public function comparisonFrom(): Carbon
    {
        return match ($this->compare) {
            'previous_year' => $this->from->copy()->subYear(),
            'none' => $this->from->copy(),
            default => $this->from->copy()->subDays($this->dayCount()),
        };
    }

    /**
     * Bei "Kein Vergleich" liegt das Ende vor dem Anfang, damit Schleifen
     * ueber den Vergleichszeitraum leer bleiben.
     */
    public function comparisonTo(): Carbon
    {
        return match ($this->compare) {
            'previous_year' => $this->to->copy()->subYear(),
            'none' => $this->from->copy()->subDay(),
            default => $this->from->copy()->subDay(),
        };
    }

    public function comparison(): ?self
    {
        return $this->hasComparison() ? new self($this->comparisonFrom(), $this->comparisonTo(), 'none', 'custom') : null;
    }

    /**
     * true, wenn ein Ablese-Intervall vollstaendig im Zeitraum liegt.
     */
    public function containsInterval(Carbon $start, Carbon $end): bool
    {
        return $start->greaterThanOrEqualTo($this->from->copy()->startOfDay())
            && $end->lessThanOrEqualTo($this->to->copy()->endOfDay());
    }

    public function label(): string
    {
        $format = 'd.m.Y';

        return $this->from->equalTo($this->to)
            ? $this->from->format($format)
            : $this->from->format($format) . ' – ' . $this->to->format($format);
    }
}
