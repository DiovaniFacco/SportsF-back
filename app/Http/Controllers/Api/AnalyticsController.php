<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessEvent;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalyticsController extends Controller
{
    /**
     * Visão geral de acessos.
     * - ADMIN: todos os complexos (métrica global).
     * - GESTOR: apenas os complexos que opera.
     */
    public function overview(Request $request): JsonResponse
    {
        return $this->buildOverview($request, null);
    }

    /**
     * Acessos de um complexo específico (dono ou admin).
     */
    public function venueOverview(Request $request, Venue $venue): JsonResponse
    {
        $user = $request->user();
        if (!$user->canOperateVenue($venue)) {
            return response()->json(['message' => 'Você não tem acesso às métricas deste complexo.'], 403);
        }

        return $this->buildOverview($request, $venue->id);
    }

    private function buildOverview(Request $request, ?int $venueId): JsonResponse
    {
        $user = $request->user();
        [$from, $to, $period] = $this->resolveRange($request);

        $base = AccessEvent::query()->whereBetween('created_at', [$from, $to]);

        if ($venueId !== null) {
            // Escopo de um complexo específico.
            $base->where('venue_id', $venueId);
        } elseif (!$user->isAdmin()) {
            // Gestor/funcionário: restringe aos complexos que opera.
            $base->whereIn('venue_id', $user->operableVenueIds() ?? []);
        }

        return response()->json([
            'period' => $period,
            'scope' => $venueId !== null ? 'venue' : ($user->isAdmin() ? 'global' : 'managed'),
            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'totals' => $this->totals(clone $base),
            'screens' => $this->screens(clone $base),
            'platforms' => $this->platforms(clone $base),
            'daily' => $this->daily(clone $base, $from, $to),
            'venues' => $this->venuesBreakdown(clone $base),
            'recent' => $this->recent(clone $base),
        ]);
    }

    /**
     * Intervalo de datas conforme o período pedido (fuso oficial do sistema).
     *
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    private function resolveRange(Request $request): array
    {
        $period = (string) $request->query('period', 'today');
        $now = Carbon::now();
        $to = $now->copy();

        switch ($period) {
            case 'week':
                $from = $now->copy()->subDays(6)->startOfDay();
                break;
            case 'month':
                $from = $now->copy()->startOfMonth();
                break;
            case '30d':
                $from = $now->copy()->subDays(29)->startOfDay();
                break;
            case 'today':
            default:
                $period = 'today';
                $from = $now->copy()->startOfDay();
                break;
        }

        return [$from, $to, $period];
    }

    /**
     * Totais do período: eventos, sessões e visitantes únicos por tipo.
     * A chave do visitante anônimo é o visitor_id; a do logado é o user_id.
     *
     * @return array<string, int>
     */
    private function totals(Builder $query): array
    {
        $row = $query->selectRaw(
            'COUNT(*) as events, '
            . 'COUNT(DISTINCT session_id) as sessions, '
            . 'COUNT(DISTINCT CASE WHEN user_id IS NULL THEN session_id END) as anonymous_sessions, '
            . 'COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN session_id END) as logged_sessions, '
            . 'COUNT(DISTINCT CASE WHEN user_id IS NULL THEN visitor_id END) as anonymous_visitors, '
            . 'COUNT(DISTINCT user_id) as logged_visitors, '
            . 'SUM(CASE WHEN user_id IS NULL THEN 1 ELSE 0 END) as anonymous_events, '
            . 'SUM(CASE WHEN user_id IS NOT NULL THEN 1 ELSE 0 END) as logged_events'
        )->first();

        $anonymous = (int) ($row->anonymous_visitors ?? 0);
        $logged = (int) ($row->logged_visitors ?? 0);
        $visitors = $anonymous + $logged;

        // "Acessos" = sessões (entradas). Cada abertura do app/site conta 1,
        // independentemente de quantas telas o usuário visitar. Uma nova
        // entrada (nova sessão) só é contada quando ele sai e volta.
        $anonymousSessions = (int) ($row->anonymous_sessions ?? 0);
        $loggedSessions = (int) ($row->logged_sessions ?? 0);
        $sessionsSum = $anonymousSessions + $loggedSessions;

        return [
            'events' => (int) ($row->events ?? 0),
            'sessions' => (int) ($row->sessions ?? 0),
            'anonymous_sessions' => $anonymousSessions,
            'logged_sessions' => $loggedSessions,
            'unique_visitors' => $visitors,
            'anonymous_visitors' => $anonymous,
            'logged_visitors' => $logged,
            'anonymous_events' => (int) ($row->anonymous_events ?? 0),
            'logged_events' => (int) ($row->logged_events ?? 0),
            'anonymous_session_percentage' => $sessionsSum > 0 ? (int) round(($anonymousSessions / $sessionsSum) * 100) : 0,
            'logged_session_percentage' => $sessionsSum > 0 ? (int) round(($loggedSessions / $sessionsSum) * 100) : 0,
            'anonymous_percentage' => $visitors > 0 ? (int) round(($anonymous / $visitors) * 100) : 0,
            'logged_percentage' => $visitors > 0 ? (int) round(($logged / $visitors) * 100) : 0,
        ];
    }

    /**
     * Telas mais acessadas (page views).
     *
     * @return array<int, array<string, mixed>>
     */
    private function screens(Builder $query): array
    {
        return $query->selectRaw('screen, COUNT(*) as views')
            ->whereNotNull('screen')
            ->groupBy('screen')
            ->orderByDesc('views')
            ->limit(12)
            ->get()
            ->map(fn ($row) => [
                'screen' => $row->screen,
                'views' => (int) $row->views,
            ])
            ->all();
    }

    /**
     * Distribuição por plataforma (web / android / ios).
     *
     * @return array<int, array<string, mixed>>
     */
    private function platforms(Builder $query): array
    {
        return $query->selectRaw("COALESCE(platform, 'desconhecido') as platform, COUNT(*) as events")
            ->groupBy('platform')
            ->orderByDesc('events')
            ->get()
            ->map(fn ($row) => [
                'platform' => $row->platform,
                'events' => (int) $row->events,
            ])
            ->all();
    }

    /**
     * Série diária de ACESSOS (sessões/entradas) por tipo de usuário,
     * preenchendo os dias sem acesso com zero.
     *
     * @return array<int, array<string, mixed>>
     */
    private function daily(Builder $query, Carbon $from, Carbon $to): array
    {
        $dateExpr = $this->dateExpression();

        $rows = $query->selectRaw(
            "{$dateExpr} as day, "
            . 'COUNT(DISTINCT CASE WHEN user_id IS NULL THEN session_id END) as anonymous, '
            . 'COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN session_id END) as logged'
        )
            ->groupByRaw($dateExpr)
            ->orderByRaw($dateExpr)
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $out = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);
            $anonymous = (int) ($row->anonymous ?? 0);
            $logged = (int) ($row->logged ?? 0);

            $out[] = [
                'date' => $key,
                'anonymous' => $anonymous,
                'logged' => $logged,
                'total' => $anonymous + $logged,
            ];

            $cursor->addDay();
        }

        return $out;
    }

    /**
     * Quebra por complexo (acessos/sessões), incluindo acessos sem complexo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function venuesBreakdown(Builder $query): array
    {
        $rows = $query->selectRaw(
            'venue_id, '
            . 'COUNT(DISTINCT CASE WHEN user_id IS NULL THEN session_id END) as anonymous_sessions, '
            . 'COUNT(DISTINCT CASE WHEN user_id IS NOT NULL THEN session_id END) as logged_sessions, '
            . 'COUNT(*) as events'
        )
            ->groupBy('venue_id')
            ->orderByDesc('events')
            ->limit(20)
            ->get();

        $names = Venue::whereIn('id', $rows->pluck('venue_id')->filter()->all())
            ->pluck('name', 'id');

        return $rows->map(function ($row) use ($names) {
            $anonymousSessions = (int) $row->anonymous_sessions;
            $loggedSessions = (int) $row->logged_sessions;

            return [
                'venue_id' => $row->venue_id,
                'name' => $row->venue_id
                    ? ($names[$row->venue_id] ?? 'Complexo removido')
                    : 'Sem complexo (raiz)',
                'anonymous_sessions' => $anonymousSessions,
                'logged_sessions' => $loggedSessions,
                'sessions' => $anonymousSessions + $loggedSessions,
                'events' => (int) $row->events,
            ];
        })->all();
    }

    /**
     * Últimos acessos registrados (com IP e se estavam logados).
     *
     * @return array<int, array<string, mixed>>
     */
    private function recent(Builder $query): array
    {
        return $query->with(['user:id,name', 'venue:id,name'])
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->map(fn (AccessEvent $event) => [
                'id' => $event->id,
                'user_id' => $event->user_id,
                'user_name' => $event->user?->name,
                'user_role' => $event->user_role,
                'anonymous' => $event->user_id === null,
                'venue_id' => $event->venue_id,
                'venue_name' => $event->venue?->name,
                'screen' => $event->screen,
                'event_type' => $event->event_type,
                'platform' => $event->platform,
                'ip' => $event->ip,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Expressão SQL para extrair a data (compatível com pgsql/mysql/sqlite).
     */
    private function dateExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => 'date(created_at)',
            'mysql', 'mariadb' => 'DATE(created_at)',
            default => 'CAST(created_at AS DATE)', // pgsql
        };
    }
}
