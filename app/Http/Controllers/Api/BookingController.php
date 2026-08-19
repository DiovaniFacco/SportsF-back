<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Booking::with(['court.venue', 'user']);

        if ($user->isGestor() && !$user->isAdmin()) {
            // Gestor sees bookings for courts in venues they own
            $query->whereHas('court.venue', function ($q) use ($user) {
                $q->where('user_id', $user->id);
            });
        } elseif (!$user->isAdmin()) {
            // Cliente sees their own bookings
            $query->where('user_id', $user->id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        $bookings = $query->orderBy('start_time', 'desc')->paginate(15);

        return response()->json($bookings);
    }

    public function store(Request $request, BookingService $bookingService): JsonResponse
    {
        $validated = $request->validate([
            'court_id' => ['required', 'exists:courts,id'],
            'start_time' => ['required', 'date_format:Y-m-d H:i:s'],
            'end_time' => ['required', 'date_format:Y-m-d H:i:s', 'after:start_time'],
            'is_manual' => ['nullable', 'boolean'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string'],
        ]);

        $extraParams = [
            'is_manual' => $validated['is_manual'] ?? false,
            'customer_name' => $validated['customer_name'] ?? null,
            'customer_phone' => $validated['customer_phone'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ];

        $booking = $bookingService->createBooking(
            $request->user(),
            $validated['court_id'],
            $validated['start_time'],
            $validated['end_time'],
            $extraParams
        );

        return response()->json([
            'message' => 'Agendamento realizado com sucesso!',
            'booking' => $booking->load(['court.venue', 'user']),
        ], 201);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $booking->user_id !== $user->id && $booking->court->venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para visualizar este agendamento.'], 403);
        }

        return response()->json([
            'booking' => $booking->load(['court.venue', 'user']),
        ]);
    }

    public function cancel(Request $request, Booking $booking, BookingService $bookingService): JsonResponse
    {
        $updatedBooking = $bookingService->cancelBooking($request->user(), $booking);

        return response()->json([
            'message' => 'Agendamento cancelado com sucesso.',
            'booking' => $updatedBooking,
        ]);
    }
}
