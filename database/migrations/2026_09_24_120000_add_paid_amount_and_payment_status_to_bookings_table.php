<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pagamento parcial: gestor informa quanto foi pago (paid_amount)
     * e o sistema define automaticamente o payment_status:
     * - pago: paid_amount >= total_price (valor exato da quadra)
     * - pendente: 0 < paid_amount < total_price (pagou só X valor)
     * - nao_pago: paid_amount <= 0 (não pagou nada)
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->decimal('paid_amount', 10, 2)->default(0)->after('total_price');
            $table->string('payment_status', 20)->default('nao_pago')->after('paid_amount');
        });

        // Backfill a partir do is_paid legado:
        // is_paid=true => pago (paid_amount = total_price), false => nao_pago (0)
        DB::statement("UPDATE bookings SET payment_status = CASE WHEN is_paid = true THEN 'pago' ELSE 'nao_pago' END");
        DB::statement('UPDATE bookings SET paid_amount = CASE WHEN is_paid = true THEN total_price ELSE 0 END');

        Schema::table('bookings', function (Blueprint $table) {
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
            $table->dropColumn(['paid_amount', 'payment_status']);
        });
    }
};
