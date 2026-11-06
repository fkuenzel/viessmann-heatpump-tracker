<?php

namespace App\Mcp\Tools\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * Gemeinsame Eingabe-Validierung und Formatierung der MCP-Tools: Datums-/
 * Zeitraum-Parameter werden einheitlich geprüft und begrenzt, damit ein
 * Client (oder das LLM dahinter) keine beliebig großen Abfragen auslösen
 * kann - die Tageswert-Abfragen sind pro Tag mehrere Datenbankzugriffe.
 */
trait InteractsWithToolInput
{
    /**
     * Inklusiver Zeitraum [from, to] auf Basis der optionalen Parameter
     * `from`/`to` (Format YYYY-MM-DD). Ohne `to` gilt heute, ohne `from`
     * die letzten $defaultDays Tage bis einschließlich `to`.
     *
     * @return array{0: Carbon, 1: Carbon}
     *
     * @throws ValidationException
     */
    protected function resolveDateRange(Request $request, int $defaultDays = 14, int $maxDays = 92): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);

        $to = isset($validated['to'])
            ? Carbon::createFromFormat('Y-m-d', $validated['to'])->startOfDay()
            : now()->startOfDay();

        $from = isset($validated['from'])
            ? Carbon::createFromFormat('Y-m-d', $validated['from'])->startOfDay()
            : $to->copy()->subDays($defaultDays - 1);

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'from' => 'Das Startdatum (from) muss vor oder gleich dem Enddatum (to) liegen.',
            ]);
        }

        $spanDays = (int) round($from->diffInDays($to)) + 1;

        if ($spanDays > $maxDays) {
            throw ValidationException::withMessages([
                'from' => "Der Zeitraum darf höchstens {$maxDays} Tage umfassen (angefragt: {$spanDays}).",
            ]);
        }

        return [$from, $to];
    }

    /**
     * Anzahl zurückliegender Tage (Parameter `days`), begrenzt auf $max.
     *
     * @throws ValidationException
     */
    protected function resolveDays(Request $request, int $default = 14, int $max = 90): int
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:' . $max],
        ]);

        return (int) ($validated['days'] ?? $default);
    }

    protected function round(?float $value, int $decimals = 2): ?float
    {
        return $value === null ? null : round($value, $decimals);
    }

    protected function iso(?\DateTimeInterface $date): ?string
    {
        return $date === null ? null : Carbon::instance($date)->toIso8601String();
    }
}
