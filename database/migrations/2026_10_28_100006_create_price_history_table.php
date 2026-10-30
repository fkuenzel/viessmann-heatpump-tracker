<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_history', function (Blueprint $table) {
            $table->id();
            $table->date('valid_from');
            $table->string('provider_name');
            $table->string('tariff_name');
            $table->decimal('base_fee', 8, 2);
            $table->enum('base_fee_period', ['monthly', 'yearly']);
            $table->decimal('ht_price', 8, 4);
            $table->decimal('nt_price', 8, 4);
            $table->timestamps();

            $table->index('valid_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_history');
    }
};
