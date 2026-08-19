<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('court_sport', function (Blueprint $table) {
            $table->foreignId('court_id')->constrained('courts')->onDelete('cascade');
            $table->foreignId('sport_id')->constrained('sports')->onDelete('cascade');
            $table->primary(['court_id', 'sport_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('court_sport');
    }
};
