<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Novo papel FUNCIONARIO (nível entre GESTOR_ESPACO e CLIENTE)
     * + vínculo por complexo via pivot venue_staff.
     *
     * Escopo do funcionário (decisão de produto):
     * - PODE: operating-hours, pricing-rules/special-prices, blockouts,
     *   fixed-schedules, bookings (ver/criar manual/cancelar/pagamento)
     *   apenas nos complexos onde está vinculado.
     * - NÃO PODE: criar/editar/excluir complexo (venues), criar/editar/excluir
     *   quadra (courts), gerenciar amenities.
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('ADMIN','GESTOR_ESPACO','CLIENTE','FUNCIONARIO') DEFAULT 'CLIENTE'");
        } elseif ($driver === 'pgsql') {
            // Laravel implementa enum no Postgres como CHECK constraint.
            // Remove checks existentes que citam a coluna role e recria com o novo valor.
            $constraints = DB::select(
                "SELECT conname FROM pg_constraint WHERE conrelid = 'users'::regclass AND pg_get_constraintdef(oid) LIKE '%role%'"
            );
            foreach ($constraints as $c) {
                DB::statement('ALTER TABLE users DROP CONSTRAINT "' . $c->conname . '"');
            }
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('ADMIN','GESTOR_ESPACO','CLIENTE','FUNCIONARIO'))");
            DB::statement("ALTER TABLE users ALTER COLUMN role SET DEFAULT 'CLIENTE'");
        }
        // sqlite (testes): enum não é fiscalizado, nada a fazer.

        Schema::create('venue_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['venue_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_staff');

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            // Reverte vínculo antes de restringir o enum (evita órfãos).
            DB::table('users')->where('role', 'FUNCIONARIO')->update(['role' => 'CLIENTE']);
            DB::statement("ALTER TABLE users MODIFY role ENUM('ADMIN','GESTOR_ESPACO','CLIENTE') DEFAULT 'CLIENTE'");
        } elseif ($driver === 'pgsql') {
            DB::table('users')->where('role', 'FUNCIONARIO')->update(['role' => 'CLIENTE']);
            $constraints = DB::select(
                "SELECT conname FROM pg_constraint WHERE conrelid = 'users'::regclass AND pg_get_constraintdef(oid) LIKE '%role%'"
            );
            foreach ($constraints as $c) {
                DB::statement('ALTER TABLE users DROP CONSTRAINT "' . $c->conname . '"');
            }
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('ADMIN','GESTOR_ESPACO','CLIENTE'))");
            DB::statement("ALTER TABLE users ALTER COLUMN role SET DEFAULT 'CLIENTE'");
        }
    }
};
