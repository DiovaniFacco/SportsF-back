<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class VenueStaffController extends Controller
{
    /**
     * Lista funcionários vinculados ao complexo. Apenas ADMIN
     * (decisão de produto: só admin gerencia funcionários).
     */
    public function index(Request $request, Venue $venue): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        return response()->json([
            'staff' => $venue->staff()->select('users.id', 'users.name', 'users.email', 'users.role', 'users.phone')->orderBy('users.name')->get(),
        ]);
    }

    /**
     * Vincula funcionário existente (user_id) ou cadastra novo (new_staff).
     * Apenas ADMIN.
     */
    public function store(Request $request, Venue $venue): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Apenas administradores podem gerenciar funcionários.'], 403);
        }

        $validated = $request->validate([
            'user_id' => ['nullable', 'exists:users,id'],
            'new_staff' => ['nullable', 'array'],
            'new_staff.name' => ['required_with:new_staff', 'string', 'max:255'],
            'new_staff.email' => ['required_with:new_staff', 'email', 'max:255', 'unique:users,email'],
            'new_staff.password' => ['required_with:new_staff', 'string', 'min:8'],
            'new_staff.phone' => ['nullable', 'string', 'max:20'],
        ]);

        if (!empty($validated['new_staff'])) {
            $staffUser = User::create([
                'name' => $validated['new_staff']['name'],
                'email' => $validated['new_staff']['email'],
                'password' => Hash::make($validated['new_staff']['password']),
                'phone' => $validated['new_staff']['phone'] ?? null,
                'role' => User::ROLE_FUNCIONARIO,
            ]);
        } elseif (!empty($validated['user_id'])) {
            $staffUser = User::findOrFail($validated['user_id']);
            if ($staffUser->isAdmin()) {
                return response()->json(['message' => 'Administradores já têm acesso total, não precisam de vínculo.'], 422);
            }
            if ($staffUser->role === User::ROLE_CLIENTE) {
                $staffUser->role = User::ROLE_FUNCIONARIO;
                $staffUser->save();
            }
            // GESTOR_ESPACO ou FUNCIONARIO: mantém o papel, só vincula.
        } else {
            return response()->json([
                'message' => 'Informe user_id ou new_staff.',
                'errors' => ['user_id' => ['Informe um usuário existente ou cadastre um novo funcionário.']],
            ], 422);
        }

        $venue->staff()->syncWithoutDetaching([$staffUser->id]);

        return response()->json([
            'message' => 'Funcionário vinculado com sucesso',
            'staff' => $venue->staff()->select('users.id', 'users.name', 'users.email', 'users.role', 'users.phone')->orderBy('users.name')->get(),
        ], 201);
    }

    /**
     * Remove vínculo do funcionário com o complexo. Apenas ADMIN.
     * Não exclui o usuário, só o vínculo (papel volta a CLIENTE se não
     * tiver nenhum outro vínculo nem complexo próprio).
     */
    public function destroy(Request $request, Venue $venue, User $user): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Apenas administradores podem gerenciar funcionários.'], 403);
        }

        $venue->staff()->detach($user->id);

        $user->refresh();
        $stillStaff = $user->staffVenues()->exists();
        $ownsVenues = $user->venues()->exists();
        if ($user->role === User::ROLE_FUNCIONARIO && !$stillStaff && !$ownsVenues) {
            $user->role = User::ROLE_CLIENTE;
            $user->save();
        }

        return response()->json(['message' => 'Vínculo de funcionário removido com sucesso.']);
    }
}
