<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Dia lançado de um horário fixo: a reserva avulsa materializa
            // aquela ocorrência (com pagamento, como agendamento normal),
            // sem remover a série.
            $table->foreignId('court_fixed_schedule_id')
                ->nullable()
                ->after('court_id')
                ->constrained('court_fixed_schedules')
                ->nullOnDelete();
            $table->index('court_fixed_schedule_id');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('court_fixed_schedule_id');
        });
    }
};
