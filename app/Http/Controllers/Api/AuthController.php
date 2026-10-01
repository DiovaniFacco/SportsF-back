<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)],
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

        $email = strtolower(trim($validated['email']));
        // Lockout por conta: cobre força-bruta distribuída contra um único e-mail
        // (o throttle por IP sozinho não segura vários IPs atacando a mesma conta).
        $lockoutKey = 'login:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($lockoutKey, 5)) {
            $seconds = RateLimiter::availableIn($lockoutKey);

            return response()->json([
                'message' => "Muitas tentativas. Tente novamente em {$seconds} segundos.",
            ], 429);
        }

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($lockoutKey, 300);

            throw ValidationException::withMessages([
                'email' => ['As credenciais fornecidas estão incorretas.'],
            ]);
        }

        RateLimiter::clear($lockoutKey);

        if ($user->role === User::ROLE_CLIENTE && $user->venues()->exists()) {
            $user->role = User::ROLE_GESTOR;
            $user->save();
        }

        // Cleanup sem scheduler: apaga tokens expirados do próprio usuário
        // a cada login (a Railway não roda cron junto do Apache).
        $user->tokens()->where('expires_at', '<', now())->delete();

        $token = $user->createToken('auth_token')->plainTextToken;

        $user->loadMissing('staffVenues:id');

        return response()->json([
            'message' => 'Login realizado com sucesso',
            'user' => $user,
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Sempre responde 200 com mensagem genérica (anti-enumeração):
     * não revela se o e-mail existe na base.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $request->input('email'))->first();

        if ($user) {
            Password::sendResetLink(['email' => $user->email]);
        }

        return response()->json([
            'message' => 'Se o e-mail estiver cadastrado, você receberá o link em instantes.',
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)],
        ]);

        $newToken = null;
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) use (&$newToken) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                // Reset bem-sucedido revoga TODAS as sessões antigas e já
                // devolve uma sessão nova (usuário entra logado direto).
                $user->tokens()->delete();
                $newToken = $user->createToken('auth_token')->plainTextToken;
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => $status === Password::INVALID_TOKEN
                    ? 'Link inválido ou expirado. Peça um novo link de recuperação.'
                    : 'Não foi possível redefinir a senha. Verifique os dados e tente novamente.',
            ], 422);
        }

        /** @var User $user */
        $user = User::where('email', $request->input('email'))->first();

        return response()->json([
            'message' => 'Senha redefinida com sucesso.',
            'user' => $user,
            'access_token' => $newToken,
            'token_type' => 'Bearer',
        ]);
    }

    /**
     * Troca de senha para usuário logado. Mantém só o token atual,
     * revoga os demais (sessões em outros dispositivos).
     */
    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'A senha atual está incorreta.',
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
        ])->save();

        $currentTokenId = $request->user()->currentAccessToken()?->id;
        $user->tokens()->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))->delete();

        return response()->json([
            'message' => 'Senha alterada com sucesso.',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->role === User::ROLE_CLIENTE && $user->venues()->exists()) {
            $user->role = User::ROLE_GESTOR;
            $user->save();
        }

        $user->loadMissing('staffVenues:id');

        return response()->json([
            'user' => $user,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Perfil atualizado com sucesso',
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

    /**
     * Revoga TODOS os tokens do usuário (sair de todos os dispositivos).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'message' => 'Todas as sessões foram encerradas.',
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
