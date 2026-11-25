<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heizstab-Erfassung (Release 2.0.0). Gleiche Charakteristik wie die
 * Thermik-Spalten: die API liefert nur den Snapshot des laufenden Tages
 * (kein day[]-Array), daher nur fuer day_index=0 geschrieben, ueber die
 * "Anlagenwerte"-Oberflaeche aber nachtragbar.
 *
 * Feature-Mapping (bestaetigt anhand storage/app/viessmann-features.json):
 * - heating_rod_heating_kwh <- heating.heatingRod.power.consumption.summary.heating (currentDay)
 * - heating_rod_dhw_kwh     <- heating.heatingRod.power.consumption.summary.dhw (currentDay)
 * - heating_rod_starts      <- heating.heatingRod.statistics (starts)
 * - heating_rod_hours       <- heating.heatingRod.statistics (hours)
 * - heating_rod_active      <- heating.heatingRod (active)
 *
 * Rein additiv: bestehende Zeilen bleiben unveraendert (alle Spalten nullable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_log', function (Blueprint $table) {
            $table->decimal('heating_rod_heating_kwh', 8, 2)->nullable()->after('compressor_phase');
            $table->decimal('heating_rod_dhw_kwh', 8, 2)->nullable()->after('heating_rod_heating_kwh');
            $table->unsignedInteger('heating_rod_starts')->nullable()->after('heating_rod_dhw_kwh');
            $table->decimal('heating_rod_hours', 9, 2)->nullable()->after('heating_rod_starts');
            $table->boolean('heating_rod_active')->nullable()->after('heating_rod_hours');
        });
    }

    public function down(): void
    {
        Schema::table('api_log', function (Blueprint $table) {
            $table->dropColumn([
                'heating_rod_heating_kwh',
                'heating_rod_dhw_kwh',
                'heating_rod_starts',
                'heating_rod_hours',
                'heating_rod_active',
            ]);
        });
    }
};
