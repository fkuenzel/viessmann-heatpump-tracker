<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('timestamp');
            $table->enum('source', ['scheduled', 'manual']);
            $table->unsignedTinyInteger('day_index');
            $table->decimal('power_heating_kwh', 8, 2)->nullable();
            $table->decimal('power_dhw_kwh', 8, 2)->nullable();
            $table->decimal('power_total_kwh', 8, 2)->nullable();
            $table->decimal('thermal_heating_kwh', 8, 2)->nullable();
            $table->decimal('thermal_dhw_kwh', 8, 2)->nullable();
            $table->decimal('outdoor_temp', 5, 2)->nullable();
            $table->unsignedInteger('compressor_starts')->nullable();
            $table->decimal('compressor_hours', 8, 2)->nullable();
            $table->timestamps();

            $table->index('timestamp');
            $table->index('day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_log');
    }
};
