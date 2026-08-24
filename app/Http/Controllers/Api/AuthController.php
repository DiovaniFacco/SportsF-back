<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use Illuminate\Support\Facades\Http;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => User::ROLE_CLIENTE,
            'phone' => $validated['phone'] ?? null,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Usuário registrado com sucesso',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['As credenciais fornecidas estão incorretas.'],
            ]);
        }

        if ($user->role === User::ROLE_CLIENTE && $user->venues()->exists()) {
            $user->role = User::ROLE_GESTOR;
            $user->save();
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login realizado com sucesso',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function googleAuth(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['nullable', 'string'],
            'access_token' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'name' => ['nullable', 'string', 'max:255'],
            'google_id' => ['nullable', 'string'],
            'avatar' => ['nullable', 'string'],
        ]);

        $googleEmail = null;
        $googleName = null;

        // 1. Try verifying id_token if provided
        if (!empty($validated['id_token'])) {
            $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $validated['id_token'],
            ]);

            if ($response->successful()) {
                $googleData = $response->json();
                $googleEmail = $googleData['email'] ?? null;
                $googleName = $googleData['name'] ?? $googleEmail;
            }
        }

        // 2. Try verifying access_token if id_token failed or wasn't provided
        if (!$googleEmail && !empty($validated['access_token'])) {
            $response = Http::withToken($validated['access_token'])
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');

            if ($response->successful()) {
                $googleData = $response->json();
                $googleEmail = $googleData['email'] ?? null;
                $googleName = $googleData['name'] ?? $googleEmail;
            }
        }

        if (!$googleEmail) {
            return response()->json([
                'message' => 'Token do Google inválido ou não foi possível verificar a autenticação.',
            ], 401);
        }

        $user = User::where('email', $googleEmail)->first();

        if (!$user) {
            $user = User::create([
                'name' => $googleName ?: 'Usuário Google',
                'email' => $googleEmail,
                'password' => Hash::make(\Illuminate\Support\Str::random(32)),
                'role' => User::ROLE_CLIENTE,
            ]);
        }

        if ($user->role === User::ROLE_CLIENTE && $user->venues()->exists()) {
            $user->role = User::ROLE_GESTOR;
            $user->save();
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Autenticação Google realizada com sucesso',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role === User::ROLE_CLIENTE && $user->venues()->exists()) {
            $user->role = User::ROLE_GESTOR;
            $user->save();
        }

        return response()->json([
            'user' => $user,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logout realizado com sucesso',
        ]);
    }

    public function indexUsers(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Acesso não autorizado.'], 403);
        }

        $users = User::select('id', 'name', 'email', 'role', 'phone')
            ->orderBy('name')
            ->get();

        return response()->json([
            'users' => $users,
        ]);
    }
}
