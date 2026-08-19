<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Venue::with(['courts.sports', 'courts.sport', 'amenities', 'reviews']);

        if ($request->filled('city')) {
            $city = trim($request->query('city'));
            $query->where('city', 'like', '%' . $city . '%');
        }

        if ($request->filled('sport') && $request->query('sport') !== 'Todos') {
            $sportName = trim($request->query('sport'));
            $query->whereHas('courts', function ($q) use ($sportName) {
                $q->whereHas('sports', function ($sq) use ($sportName) {
                    $sq->where('name', 'like', '%' . $sportName . '%')
                       ->orWhere('slug', 'like', '%' . strtolower($sportName) . '%');
                })->orWhereHas('sport', function ($sq) use ($sportName) {
                    $sq->where('name', 'like', '%' . $sportName . '%')
                       ->orWhere('slug', 'like', '%' . strtolower($sportName) . '%');
                })->orWhere('type', 'like', '%' . $sportName . '%');
            });
        }

        if ($request->filled('search')) {
            $search = trim($request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('neighborhood', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $venues = $query->paginate(15);

        return response()->json($venues);
    }

    public function cities(): JsonResponse
    {
        $cities = Venue::select('city', 'state')
            ->distinct()
            ->get()
            ->map(fn($v) => "{$v->city}, {$v->state}")
            ->values();

        return response()->json([
            'cities' => $cities,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'address' => ['required', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:20'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'size:2'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'phone' => ['nullable', 'string', 'max:20'],
            'cover_image_url' => ['nullable', 'url', 'max:500'],
            'amenity_ids' => ['nullable', 'array'],
            'amenity_ids.*' => ['exists:amenities,id'],
        ]);

        $ownerId = ($user->isAdmin() && isset($validated['user_id'])) ? $validated['user_id'] : $user->id;
        $validated['user_id'] = $ownerId;

        $amenityIds = $validated['amenity_ids'] ?? [];
        unset($validated['amenity_ids']);

        $venue = Venue::create($validated);
        if (!empty($amenityIds)) {
            $venue->amenities()->sync($amenityIds);
        }

        return response()->json([
            'message' => 'Espaço criado com sucesso',
            'venue' => $venue->load(['courts.sports', 'courts.sport', 'amenities', 'reviews']),
        ], 201);
    }

    public function show(Venue $venue): JsonResponse
    {
        return response()->json([
            'venue' => $venue->load(['courts.sports', 'courts.sport', 'courts.operatingHours', 'amenities', 'reviews']),
        ]);
    }

    public function update(Request $request, Venue $venue): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para editar este espaço.'], 403);
        }

        $validated = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'address' => ['sometimes', 'required', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:20'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'required', 'string', 'max:255'],
            'state' => ['sometimes', 'required', 'string', 'size:2'],
            'zip_code' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'phone' => ['nullable', 'string', 'max:20'],
            'cover_image_url' => ['nullable', 'url', 'max:500'],
            'amenity_ids' => ['nullable', 'array'],
            'amenity_ids.*' => ['exists:amenities,id'],
        ]);

        if (!$user->isAdmin()) {
            unset($validated['user_id']);
        }

        if (isset($validated['amenity_ids'])) {
            $venue->amenities()->sync($validated['amenity_ids']);
            unset($validated['amenity_ids']);
        }

        $venue->update($validated);

        return response()->json([
            'message' => 'Espaço atualizado com sucesso',
            'venue' => $venue->load(['courts.sports', 'courts.sport', 'amenities', 'reviews']),
        ]);
    }

    public function destroy(Request $request, Venue $venue): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin() && $venue->user_id !== $user->id) {
            return response()->json(['message' => 'Você não tem permissão para remover este espaço.'], 403);
        }

        $venue->delete();

        return response()->json([
            'message' => 'Espaço removido com sucesso',
        ]);
    }
}
