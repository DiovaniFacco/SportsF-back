<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class VenueController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Venue::with(['courts.sports', 'courts.sport', 'courts.operatingHours', 'courts.activeIndefiniteBlockout', 'amenities', 'reviews', 'owner']);

        if ($request->filled('city')) {
            $city = trim($request->query('city'));
            $query->where('city', 'ilike', '%' . $city . '%');
        }

        if ($request->filled('sport') && $request->query('sport') !== 'Todos') {
            $sportName = trim($request->query('sport'));
            $query->whereHas('courts', function ($q) use ($sportName) {
                $q->whereHas('sports', function ($sq) use ($sportName) {
                    $sq->where('name', 'ilike', '%' . $sportName . '%')
                       ->orWhere('slug', 'ilike', '%' . strtolower($sportName) . '%');
                })->orWhereHas('sport', function ($sq) use ($sportName) {
                    $sq->where('name', 'ilike', '%' . $sportName . '%')
                       ->orWhere('slug', 'ilike', '%' . strtolower($sportName) . '%');
                })->orWhere('type', 'ilike', '%' . $sportName . '%');
            });
        }

        if ($request->filled('search')) {
            $search = trim($request->query('search'));
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                  ->orWhere('city', 'ilike', "%{$search}%")
                  ->orWhere('neighborhood', 'ilike', "%{$search}%")
                  ->orWhere('address', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
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
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Apenas administradores podem criar novos complexos.'], 403);
        }

        $validated = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'new_manager' => ['nullable', 'array'],
            'new_manager.name' => ['required_with:new_manager', 'string', 'max:255'],
            'new_manager.email' => ['required_with:new_manager', 'email', 'max:255', 'unique:users,email'],
            'new_manager.password' => ['required_with:new_manager', 'string', 'min:8'],
            'new_manager.phone' => ['nullable', 'string', 'max:20'],
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
            'mode' => ['nullable', 'string', 'in:completo,simplificado'],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'amenity_ids' => ['nullable', 'array'],
            'amenity_ids.*' => ['exists:amenities,id'],
        ]);

        $ownerId = $user->id;

        if (!empty($validated['new_manager'])) {
            $newManager = User::create([
                'name' => $validated['new_manager']['name'],
                'email' => $validated['new_manager']['email'],
                'password' => Hash::make($validated['new_manager']['password']),
                'phone' => $validated['new_manager']['phone'] ?? null,
                'role' => User::ROLE_GESTOR,
            ]);
            $ownerId = $newManager->id;
        } elseif (!empty($validated['user_id'])) {
            $existingUser = User::find($validated['user_id']);
            if ($existingUser) {
                if ($existingUser->isCliente()) {
                    $existingUser->role = User::ROLE_GESTOR;
                    $existingUser->save();
                }
                $ownerId = $existingUser->id;
            }
        }

        unset($validated['new_manager']);
        $validated['user_id'] = $ownerId;

        $amenityIds = $validated['amenity_ids'] ?? [];
        unset($validated['amenity_ids']);

        $venue = Venue::create($validated);
        if (!empty($amenityIds)) {
            $venue->amenities()->sync($amenityIds);
        }

        return response()->json([
            'message' => 'Espaço criado com sucesso',
            'venue' => $venue->load(['courts.sports', 'courts.sport', 'amenities', 'reviews', 'owner']),
        ], 201);
    }

    public function show(string $identifier): JsonResponse
    {
        $query = Venue::with(['courts.sports', 'courts.sport', 'courts.operatingHours', 'courts.activeIndefiniteBlockout', 'amenities', 'reviews', 'owner']);

        if (is_numeric($identifier)) {
            $venue = $query->find((int) $identifier);
        } else {
            $slug = strtolower(trim($identifier));
            $allVenues = $query->get();
            $venue = $allVenues->first(function ($v) use ($slug) {
                $vSlug = \Illuminate\Support\Str::slug($v->name);
                return $vSlug === $slug || "{$vSlug}-{$v->id}" === $slug;
            });
        }

        if (!$venue) {
            return response()->json(['message' => 'Complexo esportivo não encontrado.'], 404);
        }

        return response()->json([
            'venue' => $venue,
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
            'new_manager' => ['nullable', 'array'],
            'new_manager.name' => ['required_with:new_manager', 'string', 'max:255'],
            'new_manager.email' => ['required_with:new_manager', 'email', 'max:255', 'unique:users,email'],
            'new_manager.password' => ['required_with:new_manager', 'string', 'min:8'],
            'new_manager.phone' => ['nullable', 'string', 'max:20'],
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
            'mode' => ['sometimes', 'string', 'in:completo,simplificado'],
            'whatsapp_number' => ['nullable', 'string', 'max:20'],
            'amenity_ids' => ['nullable', 'array'],
            'amenity_ids.*' => ['exists:amenities,id'],
        ]);

        if ($user->isAdmin()) {
            if (!empty($validated['new_manager'])) {
                $newManager = User::create([
                    'name' => $validated['new_manager']['name'],
                    'email' => $validated['new_manager']['email'],
                    'password' => Hash::make($validated['new_manager']['password']),
                    'phone' => $validated['new_manager']['phone'] ?? null,
                    'role' => User::ROLE_GESTOR,
                ]);
                $validated['user_id'] = $newManager->id;
            } elseif (!empty($validated['user_id'])) {
                $existingUser = User::find($validated['user_id']);
                if ($existingUser && $existingUser->isCliente()) {
                    $existingUser->role = User::ROLE_GESTOR;
                    $existingUser->save();
                }
            }
        } else {
            unset($validated['user_id']);
            // Apenas ADMIN pode alternar o modo do complexo (completo/simplificado).
            // O gestor configura apenas o WhatsApp e demais dados operacionais.
            unset($validated['mode']);
        }

        unset($validated['new_manager']);

        if (isset($validated['amenity_ids'])) {
            $venue->amenities()->sync($validated['amenity_ids']);
            unset($validated['amenity_ids']);
        }

        $venue->update($validated);

        if ($venue->user_id) {
            $owner = User::find($venue->user_id);
            if ($owner && $owner->isCliente()) {
                $owner->role = User::ROLE_GESTOR;
                $owner->save();
            }
        }

        return response()->json([
            'message' => 'Espaço atualizado com sucesso',
            'venue' => $venue->load(['courts.sports', 'courts.sport', 'amenities', 'reviews', 'owner']),
        ]);
    }

    public function destroy(Request $request, Venue $venue): JsonResponse
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'Apenas administradores podem remover este espaço.'], 403);
        }

        $venue->delete();

        return response()->json([
            'message' => 'Espaço removido com sucesso',
        ]);
    }
}
