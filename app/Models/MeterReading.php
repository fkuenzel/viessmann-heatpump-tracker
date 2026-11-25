<?php

namespace App\Models;

use App\Enums\MeterReadingReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class MeterReading extends Model
{
    use HasFactory;

    public const SOURCES = [
        'manual' => 'Manuell',
        'mcp' => 'MCP',
        'automatic' => 'Automatisch',
    ];

    protected $fillable = [
        'timestamp',
        'meter_number',
        'counter_ht',
        'counter_nt',
        'reason',
        'source',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'counter_ht' => 'decimal:3',
            'counter_nt' => 'decimal:3',
            'reason' => MeterReadingReason::class,
        ];
    }

    /**
     * Ablesungen, an denen ein Intervall enden kann, also alle, die keine neue
     * Messreihe beginnen (Startwert, Zaehlertausch) - und nicht die zeitlich
     * allererste Ablesung.
     */
    public function scopeIntervalEnds(Builder $query): Builder
    {
        $firstId = static::query()->orderBy('timestamp')->value('id');

        return $query
            ->when($firstId, fn (Builder $q) => $q->where('id', '!=', $firstId))
            ->whereNotIn('reason', MeterReadingReason::chainStartingValues());
    }

    /**
     * Aufeinanderfolgende Ablesungs-Paare [von, bis] in Zeitreihenfolge. Paare,
     * deren Ende eine neue Messreihe beginnt (z. B. Zaehlertausch), entfallen.
     * Mit $period nur Intervalle, die vollstaendig im Zeitraum liegen.
     *
     * @return Collection<int, array{0: MeterReading, 1: MeterReading}>
     */
    public static function intervalPairs(?\App\Support\ReportPeriod $period = null): Collection
    {
        $readings = static::query()->orderBy('timestamp')->get();
        $pairs = collect();

        foreach ($readings as $index => $reading) {
            if ($index === 0 || $reading->reason->startsNewChain()) {
                continue;
            }

            $previous = $readings[$index - 1];

            if ($period !== null && ! $period->containsInterval($previous->timestamp, $reading->timestamp)) {
                continue;
            }

            $pairs->push([$previous, $reading]);
        }

        return $pairs;
    }

    /**
     * true, wenn eine der beiden Ablesungen ein Schaetzwert ist.
     */
    public static function pairInvolvesEstimate(self $from, self $to): bool
    {
        return $from->reason->isEstimate() || $to->reason->isEstimate();
    }
}
