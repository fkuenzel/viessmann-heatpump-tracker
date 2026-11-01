<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApiLog extends Model
{
    use HasFactory;

    protected $table = 'api_log';

    protected $fillable = [
        'timestamp',
        'source',
        'day_index',
        'measured_at',
        'power_heating_kwh',
        'power_dhw_kwh',
        'power_total_kwh',
        'thermal_heating_kwh',
        'thermal_dhw_kwh',
        'outdoor_temp',
        'compressor_starts',
        'compressor_hours',
        'flow_temp',
        'return_temp',
        'system_pressure',
        'internal_pump_status',
        'volumetric_flow',
        'secondary_flow_temp',
        'compressor_active',
        'compressor_phase',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'day_index' => 'integer',
            'measured_at' => 'datetime',
            'power_heating_kwh' => 'decimal:2',
            'power_dhw_kwh' => 'decimal:2',
            'power_total_kwh' => 'decimal:2',
            'thermal_heating_kwh' => 'decimal:2',
            'thermal_dhw_kwh' => 'decimal:2',
            'outdoor_temp' => 'decimal:2',
            'compressor_starts' => 'integer',
            'compressor_hours' => 'decimal:2',
            'flow_temp' => 'decimal:2',
            'return_temp' => 'decimal:2',
            'system_pressure' => 'decimal:2',
            'volumetric_flow' => 'decimal:1',
            'secondary_flow_temp' => 'decimal:2',
            'compressor_active' => 'boolean',
        ];
    }

    public function scopeScheduled($query)
    {
        return $query->where('source', 'scheduled');
    }

    public function scopeManual($query)
    {
        return $query->where('source', 'manual');
    }

    public function scopeBackfill($query)
    {
        return $query->where('source', 'backfill');
    }

    public function scopeFinalDay($query)
    {
        return $query->where('day_index', '>', 0);
    }

    /**
     * Das Kalenderdatum, auf das sich dieser Wert tatsaechlich bezieht -
     * nicht zu verwechseln mit "timestamp", das den Zeitpunkt des API-Pulls
     * (bzw. bei Nachtraegen den frei gewaehlten Zeitpunkt) festhaelt. Bei
     * einem Sync-Lauf teilen sich alle 8 day_index-Zeilen denselben
     * "timestamp", beziehen sich aber auf unterschiedliche Tage - diese
     * Methode loest das anhand von day_index auf (siehe Fahrplan Abschnitt 5).
     */
    public function getReferenceDateAttribute(): \Illuminate\Support\Carbon
    {
        return $this->timestamp->copy()->startOfDay()->subDays($this->day_index);
    }

    /**
     * true, wenn measured_at auf 00:00 Uhr steht - die Konvention fuer eine
     * ganztaegige Messung ohne bekannte Uhrzeit (Fabian, 28.10.2026). Wird
     * in der Tabelle als "24 Std. Messung" statt einer Uhrzeit angezeigt.
     */
    public function getIsFullDayMeasurementAttribute(): bool
    {
        if ($this->measured_at === null) {
            return true;
        }

        return $this->measured_at->format('H:i:s') === '00:00:00';
    }
}
