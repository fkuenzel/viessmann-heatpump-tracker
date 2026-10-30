<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotations', function (Blueprint $table) {
            $table->id();
            $table->dateTime('timestamp');
            $table->text('note');
            $table->string('category')->nullable();
            $table->timestamps();

            $table->index('timestamp');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations');
    }
};
