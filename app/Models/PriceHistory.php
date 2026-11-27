<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PriceHistory extends Model
{
    use HasFactory;

    protected $table = 'price_history';

    protected static function booted(): void
    {
        $flush = fn () => app(\App\Services\TariffLookup::class)->flush();

        static::saved($flush);
        static::deleted($flush);
    }

    protected $fillable = [
        'valid_from',
        'provider_name',
        'tariff_name',
        'base_fee',
        'base_fee_period',
        'ht_price',
        'nt_price',
    ];

    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'base_fee' => 'decimal:2',
            'ht_price' => 'decimal:4',
            'nt_price' => 'decimal:4',
        ];
    }

    /**
     * Den für einen bestimmten Zeitpunkt gültigen Tarif ermitteln.
     */
    public static function validAt(\DateTimeInterface $date): ?self
    {
        return app(\App\Services\TariffLookup::class)->validAt($date);
    }
}
