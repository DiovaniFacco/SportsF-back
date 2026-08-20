<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operating_hours', function (Blueprint $table) {
            $table->dropUnique(['court_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::table('operating_hours', function (Blueprint $table) {
            $table->unique(['court_id', 'day_of_week']);
        });
    }
};
