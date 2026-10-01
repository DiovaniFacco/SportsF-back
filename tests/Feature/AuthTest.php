<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Nos testes o container persiste entre requests, e o guard do Sanctum
     * cacheia o usuário resolvido. Sem isso, um token válido usado num
     * request anterior mascararia um token revogado no request seguinte.
     */
    private function forgetAuthState(): void
    {
        $this->app['auth']->forgetGuards();
    }

    public function test_user_can_register_and_role_is_locked_to_cliente(): void
    {
        $payload = [
            'name' => 'João Cliente',
            'email' => 'joao@quadras.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => User::ROLE_GESTOR, // Tentar escalar privilégio no cadastro público
            'phone' => '11999999999',
        ];

        $response = $this->postJson('/api/v1/auth/register', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'email', 'role'],
                'access_token',
                'token_type',
            ]);

        // Garantir que a role foi travada em CLIENTE e ignorou a tentativa de escalar para GESTOR
        $this->assertDatabaseHas('users', [
            'email' => 'joao@quadras.com',
            'role' => User::ROLE_CLIENTE,
        ]);
    }

    public function test_register_requires_password_confirmation(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Sem Confirmação',
            'email' => 'sem@quadras.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    public function test_user_can_login_and_access_me(): void
    {
        $user = User::factory()->create([
            'email' => 'maria@cliente.com',
            'password' => bcrypt('secret123'),
            'role' => User::ROLE_CLIENTE,
        ]);

        $loginResponse = $this->postJson('/api/v1/auth/login', [
            'email' => 'maria@cliente.com',
            'password' => 'secret123',
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonStructure(['access_token']);

        $token = $loginResponse->json('access_token');

        $meResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/auth/me');

        $meResponse->assertStatus(200)
            ->assertJson([
                'user' => [
                    'email' => 'maria@cliente.com',
                    'role' => User::ROLE_CLIENTE,
                ],
            ]);
    }

    public function test_login_locks_out_after_repeated_failures(): void
    {
        User::factory()->create([
            'email' => 'alvo@quadras.com',
            'password' => bcrypt('correta123'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => 'alvo@quadras.com',
                'password' => 'errada123',
            ])->assertStatus(422);
        }

        // 6ª tentativa (mesmo com a senha correta) cai no lockout de 5 min
        $this->postJson('/api/v1/auth/login', [
            'email' => 'alvo@quadras.com',
            'password' => 'correta123',
        ])->assertStatus(429);
    }

    public function test_expired_tokens_are_cleaned_on_login(): void
    {
        $user = User::factory()->create([
            'email' => 'limpeza@quadras.com',
            'password' => bcrypt('secret123'),
        ]);

        $user->createToken('old', ['*'], now()->subDay());

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->postJson('/api/v1/auth/login', [
            'email' => 'limpeza@quadras.com',
            'password' => 'secret123',
        ])->assertStatus(200);

        // Token expirado apagado, token novo criado
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => 'old']);
    }

    public function test_forgot_password_always_returns_generic_message(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'existe@quadras.com']);

        $expected = 'Se o e-mail estiver cadastrado, você receberá o link em instantes.';

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'existe@quadras.com'])
            ->assertStatus(200)
            ->assertJson(['message' => $expected]);

        // E-mail inexistente: resposta idêntica (anti-enumeração)
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'naoexiste@quadras.com'])
            ->assertStatus(200)
            ->assertJson(['message' => $expected]);
    }

    public function test_reset_password_with_valid_token_revokes_old_sessions(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'reset@quadras.com',
            'password' => bcrypt('antiga123'),
        ]);
        $oldToken = $user->createToken('old-session')->plainTextToken;

        $token = Password::createToken($user);

        $response = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'email' => 'reset@quadras.com',
            'password' => 'novasenha123',
            'password_confirmation' => 'novasenha123',
        ]);

        $response->assertStatus(200)->assertJsonStructure(['access_token', 'user']);

        // Sessão antiga revogada
        $this->forgetAuthState();
        $this->withHeader('Authorization', 'Bearer ' . $oldToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        // Sessão nova funciona
        $this->forgetAuthState();
        $this->withHeader('Authorization', 'Bearer ' . $response->json('access_token'))
            ->getJson('/api/v1/auth/me')
            ->assertStatus(200);

        // Senha antiga não entra mais
        $this->postJson('/api/v1/auth/login', [
            'email' => 'reset@quadras.com',
            'password' => 'antiga123',
        ])->assertStatus(422);
    }

    public function test_reset_password_with_invalid_token_fails(): void
    {
        User::factory()->create(['email' => 'invalido@quadras.com']);

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'token-invalido',
            'email' => 'invalido@quadras.com',
            'password' => 'novasenha123',
            'password_confirmation' => 'novasenha123',
        ])->assertStatus(422);
    }

    public function test_user_can_change_password_and_other_sessions_revoked(): void
    {
        $user = User::factory()->create([
            'email' => 'troca@quadras.com',
            'password' => bcrypt('atual1234'),
        ]);
        $otherToken = $user->createToken('other-device')->plainTextToken;

        $login = $this->postJson('/api/v1/auth/login', [
            'email' => 'troca@quadras.com',
            'password' => 'atual1234',
        ])->assertStatus(200);
        $currentToken = $login->json('access_token');

        $this->withHeader('Authorization', 'Bearer ' . $currentToken)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'atual1234',
                'password' => 'novasenha456',
                'password_confirmation' => 'novasenha456',
            ])->assertStatus(200);

        // Token atual continua válido, o do outro dispositivo cai
        $this->forgetAuthState();
        $this->withHeader('Authorization', 'Bearer ' . $currentToken)
            ->getJson('/api/v1/auth/me')->assertStatus(200);
        $this->forgetAuthState();
        $this->withHeader('Authorization', 'Bearer ' . $otherToken)
            ->getJson('/api/v1/auth/me')->assertStatus(401);
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create([
            'email' => 'errada@quadras.com',
            'password' => bcrypt('atual1234'),
        ]);
        $token = $user->createToken('auth_token')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'nao-e-essa',
                'password' => 'novasenha456',
                'password_confirmation' => 'novasenha456',
            ])->assertStatus(422);
    }

    public function test_logout_all_revokes_every_session(): void
    {
        $user = User::factory()->create(['email' => 'todas@quadras.com']);
        $t1 = $user->createToken('a')->plainTextToken;
        $user->createToken('b');

        $this->withHeader('Authorization', 'Bearer ' . $t1)
            ->postJson('/api/v1/auth/logout-all')
            ->assertStatus(200);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_unauthenticated_me_returns_401(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
    }
}
