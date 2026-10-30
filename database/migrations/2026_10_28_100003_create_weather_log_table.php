<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weather_log', function (Blueprint $table) {
            $table->id();
            $table->dateTime('timestamp');
            $table->decimal('temperature', 5, 2);
            $table->decimal('humidity', 5, 2);
            $table->timestamps();

            $table->index('timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weather_log');
    }
};
