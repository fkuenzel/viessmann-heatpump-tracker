<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grund, Quelle und Notiz je Zaehlerstand (Release 2.0.0). Rein additiv:
 * bestehende Ablesungen erhalten "regular" bzw. - die allererste - den Grund
 * "start_value" (sie hat bisher schon kein Intervall, das an ihr endet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->string('reason')->default('regular')->after('counter_nt');
            $table->string('source')->default('manual')->after('reason');
            $table->text('note')->nullable()->after('source');
        });

        $firstId = DB::table('meter_readings')->orderBy('timestamp')->value('id');

        if ($firstId) {
            DB::table('meter_readings')->where('id', $firstId)->update(['reason' => 'start_value']);
        }
    }

    public function down(): void
    {
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropColumn(['reason', 'source', 'note']);
        });
    }
};
