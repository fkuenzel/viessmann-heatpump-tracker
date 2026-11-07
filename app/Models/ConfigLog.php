<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Aenderungsprotokoll der Anlagenkonfiguration (Heizkurve, Sollwerte,
 * Zeitprogramme usw.), siehe App\Services\ConfigChangeService. Eine Zeile
 * entsteht nur, wenn sich der Wert gegenueber der letzten Zeile desselben
 * Features geaendert hat (bzw. als Ausgangswert beim ersten Sync).
 */
class ConfigLog extends Model
{
    protected $table = 'config_log';

    protected $fillable = [
        'timestamp',
        'feature',
        'value',
        'previous_value',
        'is_baseline',
    ];

    protected function casts(): array
    {
        return [
            'timestamp' => 'datetime',
            'value' => 'array',
            'previous_value' => 'array',
            'is_baseline' => 'boolean',
        ];
    }
}
