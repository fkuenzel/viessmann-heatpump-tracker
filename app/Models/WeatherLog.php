<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeatherLog extends Model
{
    use HasFactory;

    protected $table = 'weather_log';

    protected $fillable = [
        'timestamp',
        'temperature',
        'humidity',
        // 'live_snapshot' (stuendlicher Snapshot via weather:sync) oder
        // 'archive_daily' (konsolidierter Tagesmittelwert aus Open-Meteos
        // Historical-Weather-API via weather:backfill-archive) - siehe
        // Migration 2026_10_30_190000_add_source_to_weather_log_table.
        'source',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'temperature' => 'decimal:2',
            'humidity' => 'decimal:2',
        ];
    }
}
