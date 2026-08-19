<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use App\Models\VenueReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueReviewController extends Controller
{
    public function index(Venue $venue): JsonResponse
    {
        $reviews = $venue->reviews()->with('user:id,name')->latest()->paginate(15);

        return response()->json($reviews);
    }

    public function store(Request $request, Venue $venue): JsonResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = $venue->reviews()->create([
            'user_id' => $request->user()->id,
            'rating' => $validated['rating'],
            'comment' => $validated['comment'] ?? null,
        ]);

        return response()->json([
            'message' => 'Avaliação enviada com sucesso!',
            'review' => $review->load('user:id,name'),
        ], 201);
    }
}
