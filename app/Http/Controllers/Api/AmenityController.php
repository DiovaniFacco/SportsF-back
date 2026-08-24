<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AmenityController extends Controller
{
    public function index(): JsonResponse
    {
        $amenities = Amenity::orderBy('name', 'asc')->get();

        return response()->json([
            'amenities' => $amenities,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:amenities,name'],
            'icon' => ['nullable', 'string', 'max:255'],
        ]);

        $amenity = Amenity::create([
            'name' => trim($validated['name']),
            'icon' => $validated['icon'] ?? null,
        ]);

        return response()->json([
            'message' => 'Comodidade cadastrada com sucesso',
            'amenity' => $amenity,
        ], 201);
    }

    public function destroy(Amenity $amenity): JsonResponse
    {
        $amenity->venues()->detach();
        $amenity->delete();

        return response()->json([
            'message' => 'Comodidade removida com sucesso',
        ]);
    }
}

