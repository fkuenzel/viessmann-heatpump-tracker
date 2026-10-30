<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->dateTime('timestamp');
            $table->decimal('counter_ht', 12, 3);
            $table->decimal('counter_nt', 12, 3);
            $table->timestamps();

            $table->index('timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
    }
};
