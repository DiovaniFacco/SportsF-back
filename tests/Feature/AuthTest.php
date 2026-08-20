<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_role_is_locked_to_cliente(): void
    {
        $payload = [
            'name' => 'João Cliente',
            'email' => 'joao@quadras.com',
            'password' => 'password123',
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

    public function test_google_auth_fails_without_valid_token(): void
    {
        // Requisição sem token do Google, tentando apenas fornecer google_id e email
        $response = $this->postJson('/api/v1/auth/google', [
            'email' => 'admin@quadras.com',
            'google_id' => 'fake_id_123',
        ]);

        // Deve retornar 401 Unauthenticated e recusar bypass
        $response->assertStatus(401);
    }

    public function test_google_auth_succeeds_with_valid_token(): void
    {
        \Illuminate\Support\Facades\Http::fake([
            'https://oauth2.googleapis.com/tokeninfo*' => \Illuminate\Support\Facades\Http::response([
                'email' => 'google_user@example.com',
                'name' => 'Usuário Teste Google',
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/auth/google', [
            'id_token' => 'valid_google_id_token',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'user']);

        $this->assertDatabaseHas('users', [
            'email' => 'google_user@example.com',
            'role' => User::ROLE_CLIENTE,
        ]);
    }
}
