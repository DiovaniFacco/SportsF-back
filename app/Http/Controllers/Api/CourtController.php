<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CourtController extends Controller
{
    public function index(Venue $venue): JsonResponse
    {
        return response()->json([
            'courts' => $venue->courts()->with(['sports', 'sport', 'operatingHours', 'pricingRules', 'specialPrices'])->get(),
        ]);
    }

    public function store(Request $request, Venue $venue): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para adicionar quadras neste espaço.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'sport_id' => ['nullable', 'exists:sports,id'],
            'sport_ids' => ['nullable', 'array'],
            'sport_ids.*' => ['exists:sports,id'],
            'hourly_rate' => ['required', 'numeric', 'min:0'],
            'surface' => ['nullable', 'string', 'max:150'],
            'capacity' => ['nullable', 'string', 'max:100'],
            'is_covered' => ['nullable', 'boolean'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'min_notice_hours' => ['nullable', 'integer', 'min:0'],
            'max_advance_days' => ['nullable', 'integer', 'min:1'],
            'max_active_bookings_per_user' => ['nullable', 'integer', 'min:1'],
            'operating_hours' => ['nullable', 'array'],
            'operating_hours.*.day_of_week' => ['required_with:operating_hours', 'integer', 'between:0,6'],
            'operating_hours.*.start_time' => ['required_with:operating_hours', 'string'],
            'operating_hours.*.end_time' => ['required_with:operating_hours', 'string'],
            'operating_hours.*.slot_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
        ]);

        $sportIds = $validated['sport_ids'] ?? [];
        unset($validated['sport_ids']);

        $operatingHoursData = $validated['operating_hours'] ?? null;
        unset($validated['operating_hours']);

        if (empty($validated['type'])) {
            $validated['type'] = 'Quadra';
        }

        $court = $venue->courts()->create($validated);

        if (!empty($sportIds)) {
            $court->sports()->sync($sportIds);
        } elseif ($court->sport_id) {
            $court->sports()->sync([$court->sport_id]);
        }

        if (!empty($operatingHoursData) && is_array($operatingHoursData)) {
            foreach ($operatingHoursData as $oh) {
                $court->operatingHours()->create([
                    'day_of_week' => $oh['day_of_week'],
                    'start_time' => $oh['start_time'],
                    'end_time' => $oh['end_time'],
                    'slot_duration_minutes' => $oh['slot_duration_minutes'] ?? 60,
                ]);
            }
        }

        return response()->json([
            'message' => 'Quadra criada com sucesso',
            'court' => $court->load(['sports', 'sport', 'operatingHours']),
        ], 201);
    }

    public function show(Court $court): JsonResponse
    {
        return response()->json([
            'court' => $court->load(['venue', 'sports', 'sport', 'operatingHours', 'pricingRules', 'specialPrices', 'blockouts']),
        ]);
    }

    public function update(Request $request, Court $court): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para editar esta quadra.'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'sport_id' => ['nullable', 'exists:sports,id'],
            'sport_ids' => ['nullable', 'array'],
            'sport_ids.*' => ['exists:sports,id'],
            'hourly_rate' => ['sometimes', 'required', 'numeric', 'min:0'],
            'surface' => ['nullable', 'string', 'max:150'],
            'capacity' => ['nullable', 'string', 'max:100'],
            'is_covered' => ['nullable', 'boolean'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'min_notice_hours' => ['nullable', 'integer', 'min:0'],
            'max_advance_days' => ['nullable', 'integer', 'min:1'],
            'max_active_bookings_per_user' => ['nullable', 'integer', 'min:1'],
            'operating_hours' => ['nullable', 'array'],
            'operating_hours.*.day_of_week' => ['required_with:operating_hours', 'integer', 'between:0,6'],
            'operating_hours.*.start_time' => ['required_with:operating_hours', 'string'],
            'operating_hours.*.end_time' => ['required_with:operating_hours', 'string'],
            'operating_hours.*.slot_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
        ]);

        $operatingHoursData = $validated['operating_hours'] ?? null;
        unset($validated['operating_hours']);

        if (isset($validated['sport_ids'])) {
            $court->sports()->sync($validated['sport_ids']);
            unset($validated['sport_ids']);
        }

        $court->update($validated);

        if ($operatingHoursData !== null && is_array($operatingHoursData)) {
            $court->operatingHours()->delete();
            foreach ($operatingHoursData as $oh) {
                $court->operatingHours()->create([
                    'day_of_week' => $oh['day_of_week'],
                    'start_time' => substr($oh['start_time'], 0, 5),
                    'end_time' => substr($oh['end_time'], 0, 5),
                    'slot_duration_minutes' => $oh['slot_duration_minutes'] ?? 60,
                ]);
            }
        }

        return response()->json([
            'message' => 'Quadra atualizada com sucesso',
            'court' => $court->load(['sports', 'sport', 'operatingHours', 'pricingRules', 'specialPrices']),
        ]);
    }

    public function destroy(Request $request, Court $court): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para remover esta quadra.'], 403);
        }

        $court->delete();

        return response()->json([
            'message' => 'Quadra removida com sucesso',
        ]);
    }
}
