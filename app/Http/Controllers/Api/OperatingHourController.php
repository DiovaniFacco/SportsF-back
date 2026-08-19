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
            'court_id' => $court->id,
            'operating_hours' => $court->operatingHours()->orderBy('day_of_week')->get(),
        ]);
    }

    public function store(Request $request, Court $court): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para gerenciar horários desta quadra.'], 403);
        }

        $validated = $request->validate([
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'slot_duration_minutes' => ['nullable', 'integer', 'min:15', 'max:240'],
        ]);

        $operatingHour = OperatingHour::updateOrCreate(
            [
                'court_id' => $court->id,
                'day_of_week' => $validated['day_of_week'],
            ],
            [
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'slot_duration_minutes' => $validated['slot_duration_minutes'] ?? 60,
            ]
        );

        return response()->json([
            'message' => 'Horário de funcionamento salvo com sucesso',
            'operating_hour' => $operatingHour,
        ]);
    }

    public function availableSlots(Request $request, Court $court, BookingSlotService $slotService): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        $result = $slotService->getAvailableSlotsForCourt($court, $validated['date']);

        return response()->json($result);
    }
}
