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
