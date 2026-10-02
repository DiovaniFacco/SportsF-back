<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            if (Schema::hasColumn('courts', 'capacity')) {
                $table->dropColumn('capacity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            if (!Schema::hasColumn('courts', 'capacity')) {
                $table->string('capacity')->nullable()->after('surface');
            }
        });
    }
};
