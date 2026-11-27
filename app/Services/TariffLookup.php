<?php

namespace App\Services;

use App\Models\PriceHistory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Tarif-Nachschlagen ueber einen einmal pro Request geladenen Bestand.
 *
 * price_history ist klein (eine Zeile je Tarifwechsel), wurde aber je Tag und
 * je Ablese-Intervall einzeln abgefragt. Der Bestand wird beim Speichern oder
 * Loeschen eines PriceHistory-Eintrags verworfen (siehe PriceHistory::booted()).
 */
class TariffLookup
{
    /** @var Collection<int, PriceHistory>|null nach valid_from absteigend */
    private ?Collection $tariffs = null;

    public function flush(): void
    {
        $this->tariffs = null;
    }

    public function any(): bool
    {
        return $this->all()->isNotEmpty();
    }

    public function validAt(\DateTimeInterface $date): ?PriceHistory
    {
        $moment = Carbon::instance($date);

        return $this->all()->first(fn (PriceHistory $t): bool => $this->validFrom($t)->lte($moment));
    }

    public function changedBetween(\DateTimeInterface $after, \DateTimeInterface $until): bool
    {
        $after = Carbon::instance($after);
        $until = Carbon::instance($until);

        return $this->all()->contains(function (PriceHistory $t) use ($after, $until): bool {
            $from = $this->validFrom($t);

            return $from->gt($after) && $from->lte($until);
        });
    }

    /**
     * @return Collection<int, PriceHistory>
     */
    private function all(): Collection
    {
        return $this->tariffs ??= PriceHistory::query()->orderByDesc('valid_from')->orderByDesc('id')->get();
    }

    /**
     * Rohwert statt Date-Cast, damit eine gespeicherte Uhrzeit nicht abgeschnitten wird.
     */
    private function validFrom(PriceHistory $tariff): Carbon
    {
        return Carbon::parse($tariff->getRawOriginal('valid_from'));
    }
}
