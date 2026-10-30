<?php

use App\Models\ApiLog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * measured_at haelt die tatsaechliche Messuhrzeit fest - getrennt von
 * "timestamp" (Sync-Pull-Zeitpunkt bzw. frei gewaehlter Zeitpunkt bei
 * Nachtraegen, siehe api_log.timestamp).
 *
 * Fuer day_index=0 (heute): kommt von der API ("dayValueReadAt" des
 * heating.power.consumption.total-Features) - der Zeitpunkt, zu dem die
 * Anlage den Tageswert zuletzt berechnet hat.
 * Fuer day_index>0 (abgeschlossene Tage) und manuelle Nachtraege ohne
 * bekannte Uhrzeit: 00:00 Uhr des jeweiligen Messdatums - Konvention fuer
 * "ganztaegige Messung" (Fabian, 28.10.2026), angezeigt als "24 Std. Messung"
 * statt einer Uhrzeit.
 *
 * Bestandsdaten werden direkt mit befuellt: day_index=0 -> timestamp selbst
 * (beste verfuegbare Naeherung, dayValueReadAt wurde historisch nicht
 * erfasst), day_index>0 -> 00:00 Uhr des Messdatums.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_log', function (Blueprint $table) {
            $table->dateTime('measured_at')->nullable()->after('day_index');
        });

        ApiLog::query()->whereNull('measured_at')->each(function (ApiLog $row) {
            $measuredAt = $row->day_index === 0
                ? $row->timestamp->copy()
                : $row->timestamp->copy()->startOfDay()->subDays($row->day_index);

            $row->timestamps = false;
            $row->measured_at = $measuredAt;
            $row->save();
        });
    }

    public function down(): void
    {
        Schema::table('api_log', function (Blueprint $table) {
            $table->dropColumn('measured_at');
        });
    }
};
