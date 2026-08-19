<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register(): void
    {
        $payload = [
            'name' => 'João Gestor',
            'email' => 'joao@quadras.com',
            'password' => 'password123',
            'role' => User::ROLE_GESTOR,
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

        $this->assertDatabaseHas('users', [
            'email' => 'joao@quadras.com',
            'role' => User::ROLE_GESTOR,
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
}
