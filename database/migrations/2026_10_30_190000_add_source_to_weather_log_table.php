<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weather_log', function (Blueprint $table) {
            // Unterscheidet den bisherigen stuendlichen Snapshot (live_snapshot,
            // via weather:sync) von echten, konsolidierten Tagesmittelwerten aus
            // Open-Meteos Historical-Weather-API (archive_daily, via
            // weather:backfill-archive) - Fabians Entscheidung 30.10.2026 fuer
            // die Seite "Temperaturvergleich": die Archiv-API ist unabhaengig
            // davon, ob der lokale Scheduler zum jeweiligen Zeitpunkt lief,
            // waehrend live_snapshot-Zeilen bei Ausfallzeiten Luecken haben
            // (gleiche Einschraenkung wie bei thermal_heating_kwh/outdoor_temp
            // in api_log, siehe Fahrplan Abschnitt 4.10).
            //
            // Bestehende Zeilen bekommen per Default rueckwirkend
            // "live_snapshot" (das war bisher implizit die einzige Quelle).
            $table->string('source')->default('live_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('weather_log', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
