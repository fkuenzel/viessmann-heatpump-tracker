<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Neue Snapshot-Spalten fuer Live-Geraetewerte analog zur ViCare-App
 * "Geraetestatus"-Seite (Fabians Wunsch, 30.10.2026) - Vorlauf-/
 * Ruecklauftemperatur, Anlagendruck, interne Pumpe, Volumenstrom,
 * Vorlauftemperatur Sekundaerkreis, Verdichterstatus. Gleiche Charakteristik
 * wie thermal_heating_kwh/outdoor_temp/compressor_* (Abschnitt 4.10/4.17):
 * kein day[]-Array in der API, nur aktueller Snapshot per Sync fuer
 * day_index=0, aber ueber die "Anlagenwerte"-Oberflaeche auch fuer
 * vergangene Tage nachtragbar.
 *
 * Feature-Mapping (bestaetigt per php artisan viessmann:explore-features,
 * 30.10.2026):
 * - flow_temp              <- heating.boiler.sensors.temperature.commonSupply
 * - return_temp            <- heating.sensors.temperature.return
 * - system_pressure        <- heating.sensors.pressure.supply
 * - internal_pump_status   <- heating.boiler.pumps.internal (status: "on"/"off")
 * - volumetric_flow        <- heating.sensors.volumetricFlow.allengra
 * - secondary_flow_temp    <- heating.secondaryCircuit.sensors.temperature.supply
 * - compressor_active      <- heating.compressors.0 (active: bool)
 * - compressor_phase       <- heating.compressors.0 (phase: string, z. B. "ready")
 *
 * compressor_starts/compressor_hours existieren bereits (siehe
 * 2026_10_28_100002_create_api_log_table.php) und werden hier nicht erneut
 * angelegt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_log', function (Blueprint $table) {
            $table->decimal('flow_temp', 5, 2)->nullable()->after('compressor_hours');
            $table->decimal('return_temp', 5, 2)->nullable()->after('flow_temp');
            $table->decimal('system_pressure', 5, 2)->nullable()->after('return_temp');
            $table->string('internal_pump_status')->nullable()->after('system_pressure');
            $table->decimal('volumetric_flow', 7, 1)->nullable()->after('internal_pump_status');
            $table->decimal('secondary_flow_temp', 5, 2)->nullable()->after('volumetric_flow');
            $table->boolean('compressor_active')->nullable()->after('secondary_flow_temp');
            $table->string('compressor_phase')->nullable()->after('compressor_active');
        });
    }

    public function down(): void
    {
        Schema::table('api_log', function (Blueprint $table) {
            $table->dropColumn([
                'flow_temp',
                'return_temp',
                'system_pressure',
                'internal_pump_status',
                'volumetric_flow',
                'secondary_flow_temp',
                'compressor_active',
                'compressor_phase',
            ]);
        });
    }
};
