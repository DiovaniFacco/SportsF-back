<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\CourtBlockout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourtBlockoutController extends Controller
{
    public function index(Request $request, ?Court $court = null): JsonResponse
    {
        $user = $request->user();
        $query = CourtBlockout::query();

        if ($court && $court->exists) {
            $query->where('court_id', $court->id);
        } else if ($request->has('court_id')) {
            $query->where('court_id', $request->query('court_id'));
        } else if ($user) {
            if (!$user->isAdmin()) {
                $courtIds = Court::whereHas('venue', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })->pluck('id');
                $query->whereIn('court_id', $courtIds);
            }
        }

        if ($request->has('date')) {
            $date = $request->query('date');
            // Inclui bloqueios do dia + indeterminados que já começaram
            // (indeterminado bloqueia todos os dias seguintes até desbloquear).
            $query->where(function ($q) use ($date) {
                $q->whereDate('start_time', $date)
                    ->orWhere(function ($iq) use ($date) {
                        $iq->where(function ($w) {
                            $w->where('is_indefinite', true)->orWhereNull('end_time');
                        })->where('start_time', '<', $date . ' 23:59:59');
                    });
            });
        }

        if ($request->boolean('indefinite_only')) {
            $query->where(function ($q) {
                $q->where('is_indefinite', true)->orWhereNull('end_time');
            });
        }

        $blockouts = $query->orderBy('start_time')->get();

        return response()->json([
            'success' => true,
            'data' => $blockouts,
        ]);
    }

    public function store(Request $request, Court $court): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para gerenciar bloqueios nesta quadra.'], 403);
        }

        $validated = $request->validate([
            'start_time' => ['required', 'date_format:Y-m-d H:i:s'],
            'end_time' => ['nullable', 'date_format:Y-m-d H:i:s', 'after:start_time'],
            'reason' => ['nullable', 'string', 'max:255'],
            'is_indefinite' => ['nullable', 'boolean'],
        ]);

        $isIndefinite = filter_var($validated['is_indefinite'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($isIndefinite) {
            // Bloqueio indeterminado: sem previsão de término, só desbloqueia
            // quando o gestor remover (DELETE /blockouts/{id}).
            $validated['end_time'] = null;
        } elseif (empty($validated['end_time'])) {
            return response()->json([
                'message' => 'O campo fim do bloqueio é obrigatório para bloqueios com período definido.',
                'errors' => ['end_time' => ['O campo fim do bloqueio é obrigatório, exceto para bloqueio indeterminado.']],
            ], 422);
        }

        // Evita dois bloqueios indeterminados ativos simultâneos na mesma quadra
        if ($isIndefinite) {
            $existing = CourtBlockout::where('court_id', $court->id)
                ->indefiniteActive($validated['start_time'])
                ->exists();
            if ($existing) {
                return response()->json([
                    'message' => 'Esta quadra já possui um bloqueio indeterminado ativo.',
                    'errors' => ['is_indefinite' => ['Esta quadra já possui um bloqueio indeterminado ativo. Remova o bloqueio atual para criar outro.']],
                ], 422);
            }
        }

        $blockout = $court->blockouts()->create([
            'user_id' => $user->id,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'reason' => $validated['reason'] ?? null,
            'is_indefinite' => $isIndefinite,
        ]);

        return response()->json([
            'success' => true,
            'message' => $isIndefinite
                ? 'Quadra bloqueada por tempo indeterminado. A quadra ficará indisponível até você desbloquear.'
                : 'Bloqueio de agenda criado com sucesso',
            'data' => $blockout,
            'blockout' => $blockout,
        ], 201);
    }

    public function destroy(Request $request, CourtBlockout $blockout): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $blockout->court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para remover este bloqueio.'], 403);
        }

        $wasIndefinite = (bool) ($blockout->is_indefinite || is_null($blockout->end_time));
        $blockout->delete();

        return response()->json([
            'success' => true,
            'message' => $wasIndefinite
                ? 'Quadra desbloqueada com sucesso e liberada para agendamentos.'
                : 'Bloqueio removido com sucesso',
        ]);
    }
}
