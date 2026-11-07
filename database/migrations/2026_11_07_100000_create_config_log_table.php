<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_log', function (Blueprint $table) {
            $table->id();

            // Zeitpunkt des Syncs, bei dem die Aenderung erkannt wurde. Die API
            // liefert fuer Konfigurations-Features keinen echten Aenderungs-
            // zeitpunkt (deren "timestamp" ist nur der Abrufzeitpunkt), die
            // Genauigkeit entspricht also dem Sync-Intervall (15 Minuten).
            $table->dateTime('timestamp')->index();

            // Name des Viessmann-Features, z. B. heating.circuits.0.heating.curve
            $table->string('feature')->index();

            // Nur die protokollierten Eigenschaften des Features als JSON
            // (z. B. {"slope":1.2,"shift":0}); previous_value ist beim
            // Ausgangswert null.
            $table->json('value');
            $table->json('previous_value')->nullable();

            // true = Ausgangswert beim ersten Sync (keine echte Aenderung,
            // erzeugt deshalb auch keine automatische Notiz).
            $table->boolean('is_baseline')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_log');
    }
};
