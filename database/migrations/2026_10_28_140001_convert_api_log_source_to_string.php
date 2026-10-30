<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loest die starre enum('scheduled','manual')-Spalte durch eine einfache
 * String-Spalte ab, damit ein dritter Wert ('backfill') moeglich wird, ohne
 * doctrine/dbal zu benoetigen (SQLite-enum ist ein CHECK-Constraint, das sich
 * nicht per change() anpassen laesst - stattdessen Spalte neu anlegen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_log', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('api_log', function (Blueprint $table) {
            $table->string('source')->default('scheduled')->after('timestamp');
        });
    }

    public function down(): void
    {
        Schema::table('api_log', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('api_log', function (Blueprint $table) {
            $table->enum('source', ['scheduled', 'manual'])->after('timestamp');
        });
    }
};
