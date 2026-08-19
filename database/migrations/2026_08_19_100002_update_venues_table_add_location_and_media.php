<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->string('number', 20)->nullable()->after('address');
            $table->string('neighborhood')->nullable()->after('number');
            $table->string('zip_code', 20)->nullable()->after('state');
            $table->decimal('latitude', 10, 8)->nullable()->after('zip_code');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->string('cover_image_url')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn([
                'number',
                'neighborhood',
                'zip_code',
                'latitude',
                'longitude',
                'cover_image_url',
            ]);
        });
    }
};
