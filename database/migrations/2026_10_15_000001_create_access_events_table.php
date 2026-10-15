<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Registro bruto de acessos (analytics de tráfego / audiência).
     *
     * Cada linha representa uma abertura do app (`app_open`) ou a
     * visualização de uma tela (`page_view`).
     *
     * Regras:
     * - user_id NULL            => visitante NÃO logado (anônimo) — o foco desta feature.
     * - user_id preenchido      => usuário logado; guardamos também a role do momento.
     * - visitor_id              => identificador anônimo persistido no dispositivo.
     * - session_id              => agrupa as telas de uma mesma sessão (abertura do app).
     * - venue_id                => complexo acessado (quando aplicável).
     * - ip                      => IP completo (decisão de produto).
     */
    public function up(): void
    {
        Schema::create('access_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_role', 32)->nullable();
            $table->string('visitor_id', 64)->nullable();
            $table->string('session_id', 64)->nullable();
            $table->string('event_type', 32)->default('page_view');
            $table->string('screen', 64)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('referrer', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            // Consultas de período + escopo (global, por complexo, por usuário/visitante).
            $table->index('created_at');
            $table->index(['venue_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['visitor_id', 'created_at']);
            $table->index(['session_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_events');
    }
};
