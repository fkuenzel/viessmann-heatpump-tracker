<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeterReading extends Model
{
    use HasFactory;

    protected $fillable = [
        'timestamp',
        'meter_number',
        'counter_ht',
        'counter_nt',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'counter_ht' => 'decimal:3',
            'counter_nt' => 'decimal:3',
        ];
    }
}
