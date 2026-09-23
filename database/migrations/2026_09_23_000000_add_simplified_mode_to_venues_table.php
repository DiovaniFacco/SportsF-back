<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Modo Simplificado: agenda visual + agendamento via WhatsApp.
     * - mode: 'completo' (padrão, comportamento atual) | 'simplificado' (somente consulta + WhatsApp)
     * - whatsapp_number: número usado para gerar o link wa.me no modo simplificado.
     */
    public function up(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->string('mode', 20)->default('completo')->after('cancel_limit_hours');
            $table->string('whatsapp_number', 20)->nullable()->after('mode');
        });
    }

    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn(['mode', 'whatsapp_number']);
        });
    }
};
