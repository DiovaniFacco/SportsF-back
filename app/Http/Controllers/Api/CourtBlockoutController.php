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
            $query->whereDate('start_time', $date);
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
            'end_time' => ['required', 'date_format:Y-m-d H:i:s', 'after:start_time'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $blockout = $court->blockouts()->create([
            'user_id' => $user->id,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'reason' => $validated['reason'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Bloqueio de agenda criado com sucesso',
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

        $blockout->delete();

        return response()->json([
            'success' => true,
            'message' => 'Bloqueio removido com sucesso',
        ]);
    }
}
