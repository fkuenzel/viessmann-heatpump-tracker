<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WeatherForecastLog extends Model
{
    use HasFactory;

    protected $table = 'weather_forecast_log';

    protected $fillable = [
        'fetched_at',
        'forecast_for',
        'forecast_temperature',
        'forecast_humidity',
    ];

    protected function casts(): array
    {
        return [
            'fetched_at' => 'datetime',
            'forecast_for' => 'datetime',
            'forecast_temperature' => 'decimal:2',
            'forecast_humidity' => 'decimal:2',
        ];
    }
}
