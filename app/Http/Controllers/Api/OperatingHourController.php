<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Court;
use App\Models\OperatingHour;
use App\Services\BookingSlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperatingHourController extends Controller
{
    public function index(Court $court): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'court_id' => $court->id,
                'operating_hours' => $court->operatingHours()
                    ->orderBy('day_of_week')
                    ->orderBy('start_time')
                    ->get(),
            ]
        ]);
    }

    public function store(Request $request, Court $court): JsonResponse
    {
        $user = $request->user();
        if (!$user->canOperateCourt($court)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para gerenciar horários desta quadra.'
            ], 403);
        }

        // Turnos são sempre no mesmo dia (o motor de reservas/slots não
        // suporta virada de meia-noite): fim deve ser após o início.
        // O formato estrito evita 500 no Carbon com lixo ("abc").
        $validated = $request->validate([
            'id' => ['nullable', 'integer', 'exists:operating_hours,id'],
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'slot_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
        ], [
            'end_time.after' => 'O horário de término deve ser após o horário de início (turnos sempre no mesmo dia).',
        ]);

        $validated['start_time'] = substr($validated['start_time'], 0, 5);
        $validated['end_time'] = substr($validated['end_time'], 0, 5);

        $startTimeStr = $validated['start_time'] . ':00';
        $endTimeStr = $validated['end_time'] . ':00';

        // Check overlap with existing shifts for this court and day
        $overlapQuery = OperatingHour::where('court_id', $court->id)
            ->where('day_of_week', $validated['day_of_week'])
            ->where(function ($query) use ($startTimeStr, $endTimeStr) {
                $query->where(function ($q) use ($startTimeStr, $endTimeStr) {
                    $q->where('start_time', '<', $endTimeStr)
                      ->where('end_time', '>', $startTimeStr);
                });
            });

        if (!empty($validated['id'])) {
            $overlapQuery->where('id', '!=', $validated['id']);
        }

        if ($overlapQuery->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'O horário informado sobrepõe outro turno já cadastrado para este dia.'
            ], 422);
        }

        if (!empty($validated['id'])) {
            $operatingHour = OperatingHour::where('court_id', $court->id)->findOrFail($validated['id']);
            $operatingHour->update([
                'day_of_week' => $validated['day_of_week'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'slot_duration_minutes' => $validated['slot_duration_minutes'] ?? $operatingHour->slot_duration_minutes ?? 60,
            ]);
        } else {
            $operatingHour = OperatingHour::create([
                'court_id' => $court->id,
                'day_of_week' => $validated['day_of_week'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'slot_duration_minutes' => $validated['slot_duration_minutes'] ?? 60,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Horário de funcionamento salvo com sucesso',
            'data' => $operatingHour,
            'operating_hour' => $operatingHour,
        ]);
    }

    public function destroy(Request $request, OperatingHour $operatingHour): JsonResponse
    {
        $user = $request->user();
        if (!$user->canOperateCourt($operatingHour->court)) {
            return response()->json([
                'success' => false,
                'message' => 'Você não tem permissão para remover este turno.'
            ], 403);
        }

        $operatingHour->delete();

        return response()->json([
            'success' => true,
            'message' => 'Turno de funcionamento removido com sucesso.',
        ]);
    }

    public function availableSlots(Request $request, Court $court, BookingSlotService $slotService): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'include_past' => ['nullable', 'boolean'],
        ]);

        $includePast = $request->boolean('include_past');

        $result = $slotService->getAvailableSlotsForCourt($court, $validated['date'], $includePast);

        return response()->json(array_merge(['success' => true], $result));
    }
}

