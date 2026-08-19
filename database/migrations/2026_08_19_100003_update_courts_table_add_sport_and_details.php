<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->foreignId('sport_id')->nullable()->after('venue_id')->constrained('sports')->nullOnDelete();
            $table->string('surface')->nullable()->after('hourly_rate');
            $table->string('capacity')->nullable()->after('surface');
            $table->boolean('is_covered')->default(true)->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->dropForeign(['sport_id']);
            $table->dropColumn(['sport_id', 'surface', 'capacity', 'is_covered']);
        });
    }
};
