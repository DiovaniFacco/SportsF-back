<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->integer('min_notice_hours')->default(1)->after('hourly_rate');
            $table->integer('max_advance_days')->default(30)->after('min_notice_hours');
            $table->integer('max_active_bookings_per_user')->default(3)->after('max_advance_days');
        });
    }

    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->dropColumn(['min_notice_hours', 'max_advance_days', 'max_active_bookings_per_user']);
        });
    }
};
