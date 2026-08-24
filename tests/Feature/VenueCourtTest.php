<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VenueCourtTest extends TestCase
{
    use RefreshDatabase;

    public function test_gestor_can_create_venue_and_courts(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $venueResponse = $this->actingAs($admin)
            ->postJson('/api/v1/venues', [
                'user_id' => $gestor->id,
                'name' => 'Arena Central',
                'description' => 'Complexo esportivo com várias quadras',
                'address' => 'Rua das Esportes, 100',
                'city' => 'São Paulo',
                'state' => 'SP',
                'phone' => '11988888888',
            ]);

        $venueResponse->assertStatus(201)
            ->assertJson([
                'venue' => [
                    'name' => 'Arena Central',
                    'city' => 'São Paulo',
                ],
            ]);

        $venueId = $venueResponse->json('venue.id');

        $courtResponse = $this->actingAs($gestor)
            ->postJson("/api/v1/venues/{$venueId}/courts", [
                'name' => 'Quadra Society 1',
                'type' => 'society',
                'hourly_rate' => 150.00,
                'image_url' => 'https://images.unsplash.com/photo-1574629810360-7efbbe195018',
            ]);

        $courtResponse->assertStatus(201)
            ->assertJson([
                'court' => [
                    'name' => 'Quadra Society 1',
                    'hourly_rate' => 150,
                    'image_url' => 'https://images.unsplash.com/photo-1574629810360-7efbbe195018',
                ],
            ]);

        $this->assertDatabaseHas('courts', [
            'venue_id' => $venueId,
            'name' => 'Quadra Society 1',
            'image_url' => 'https://images.unsplash.com/photo-1574629810360-7efbbe195018',
        ]);
    }

    public function test_cliente_cannot_create_venue(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CLIENTE]);

        $response = $this->actingAs($cliente)
            ->postJson('/api/v1/venues', [
                'name' => 'Arena Não Permitida',
                'address' => 'Rua X',
                'city' => 'Campinas',
                'state' => 'SP',
            ]);

        $response->assertStatus(403);
    }
}
