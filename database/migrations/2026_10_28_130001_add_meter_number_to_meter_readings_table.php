<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->string('meter_number')->nullable()->after('timestamp');
        });
    }

    public function down(): void
    {
        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropColumn('meter_number');
        });
    }
};
