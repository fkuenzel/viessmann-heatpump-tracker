<?php

namespace App\Services;

use App\Enums\MeterReadingReason;
use App\Models\MeterReading;
use Illuminate\Support\Carbon;

/**
 * Plausibilitaetspruefung fuer Zaehlerstaende - gemeinsam genutzt von der
 * Filament-Oberflaeche und (ab Phase 5) vom MCP-Server.
 *
 * errors(): harte Fehler, die ein Speichern verhindern (Zaehlerstand faellt
 * gegenueber dem Vorgaenger bzw. steigt ueber den Nachfolger).
 * warnings(): Hinweise nach dem Speichern (ungewoehnlicher Korrekturfaktor).
 */
class MeterReadingPlausibilityService
{
    /** Untere/obere Grenze des Korrekturfaktors (Zaehler / App), ausserhalb davon wird gewarnt. */
    public const FACTOR_MIN = 0.8;

    public const FACTOR_MAX = 1.25;

    public function __construct(private readonly MeterCorrectionService $correction)
    {
    }

    /**
     * @return array<int, string>
     */
    public function errors(Carbon $timestamp, float $ht, float $nt, MeterReadingReason $reason, ?int $ignoreId = null): array
    {
        if ($reason->startsNewChain()) {
            // Neuer Zaehler bzw. Startwert: Zaehlerstand darf frei gewaehlt werden.
            return [];
        }

        $errors = [];

        $previous = MeterReading::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('timestamp', '<', $timestamp)
            ->orderByDesc('timestamp')
            ->first();

        if ($previous) {
            if ($ht < (float) $previous->counter_ht) {
                $errors[] = sprintf('HT-Stand liegt unter der Ablesung vom %s (%s kWh).', $previous->timestamp->format('d.m.Y H:i'), $this->fmt($previous->counter_ht));
            }

            if ($nt < (float) $previous->counter_nt) {
                $errors[] = sprintf('NT-Stand liegt unter der Ablesung vom %s (%s kWh).', $previous->timestamp->format('d.m.Y H:i'), $this->fmt($previous->counter_nt));
            }
        }

        $next = MeterReading::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('timestamp', '>', $timestamp)
            ->orderBy('timestamp')
            ->first();

        // Folgt ein Zaehlertausch/Startwert, beginnt dort eine neue Reihe.
        if ($next && ! $next->reason->startsNewChain()) {
            if ($ht > (float) $next->counter_ht) {
                $errors[] = sprintf('HT-Stand liegt über der Ablesung vom %s (%s kWh).', $next->timestamp->format('d.m.Y H:i'), $this->fmt($next->counter_ht));
            }

            if ($nt > (float) $next->counter_nt) {
                $errors[] = sprintf('NT-Stand liegt über der Ablesung vom %s (%s kWh).', $next->timestamp->format('d.m.Y H:i'), $this->fmt($next->counter_nt));
            }
        }

        return $errors;
    }

    /**
     * Hinweise zu einer bereits gespeicherten Ablesung (Intervall zum Vorgaenger).
     *
     * @return array<int, string>
     */
    public function warnings(MeterReading $reading): array
    {
        if ($reading->reason->startsNewChain()) {
            return [];
        }

        $previous = MeterReading::query()
            ->where('timestamp', '<', $reading->timestamp)
            ->orderByDesc('timestamp')
            ->first();

        if (! $previous) {
            return [];
        }

        $warnings = [];
        $result = $this->correction->calculate($previous, $reading);
        $factor = $result['correction_factor'];

        if ($factor !== null && ($factor < self::FACTOR_MIN || $factor > self::FACTOR_MAX)) {
            $warnings[] = sprintf(
                'Der Korrekturfaktor dieses Intervalls beträgt %s (üblich: %s bis %s). Bitte Zählerstand und Zeitpunkt prüfen.',
                number_format($factor, 2, ',', '.'),
                number_format(self::FACTOR_MIN, 2, ',', '.'),
                number_format(self::FACTOR_MAX, 2, ',', '.'),
            );
        }

        if ($previous->timestamp->diffInMinutes($reading->timestamp) < 60) {
            $warnings[] = 'Der Abstand zur vorherigen Ablesung beträgt weniger als eine Stunde.';
        }

        return $warnings;
    }

    private function fmt(mixed $value): string
    {
        return number_format((float) $value, 3, ',', '.');
    }
}
