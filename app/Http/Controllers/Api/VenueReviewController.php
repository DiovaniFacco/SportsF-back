<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Venue;
use App\Models\VenueReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueReviewController extends Controller
{
    public function index(Venue $venue): JsonResponse
    {
        $reviews = $venue->reviews()->with('user:id,name')->latest()->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $reviews,
        ]);
    }

    public function store(Request $request, Venue $venue): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        // Regra 8: Apenas clientes com agendamento concluído (confirmado e com horário final passado) podem avaliar
        $hasCompletedBooking = Booking::where('user_id', $user->id)
            ->whereHas('court', function ($query) use ($venue) {
                $query->where('venue_id', $venue->id);
            })
            ->where(function ($q) {
                $q->where('status', Booking::STATUS_CONFIRMED)
                  ->where('end_time', '<=', now());
            })
            ->exists();

        if (!$hasCompletedBooking) {
            return response()->json([
                'success' => false,
                'message' => 'Apenas clientes que concluíram um agendamento neste espaço podem enviar avaliações.',
            ], 403);
        }

        $review = $venue->reviews()->create([
            'user_id' => $user->id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Avaliação enviada com sucesso!',
            'data' => $review->load('user:id,name'),
        ], 201);
    }
}
