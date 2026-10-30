<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_forecast_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('fetched_at');
            $table->dateTime('forecast_for');
            $table->decimal('forecast_temperature', 5, 2);
            $table->decimal('forecast_humidity', 5, 2);
            $table->timestamps();

            $table->index('fetched_at');
            $table->index('forecast_for');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_forecast_log');
    }
};
