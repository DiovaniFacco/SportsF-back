<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function makeVenue(User $owner, array $overrides = []): Venue
    {
        return Venue::create(array_merge([
            'user_id' => $owner->id,
            'name' => 'Arena Teste',
            'address' => 'Rua A, 100',
            'city' => 'São Paulo',
            'state' => 'SP',
        ], $overrides));
    }

    public function test_anonymous_visit_is_tracked_without_user_and_stores_ip(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->postJson('/api/v1/analytics/track', [
                'event_type' => 'page_view',
                'screen' => 'complexo',
                'visitor_id' => 'visitor-abc',
                'session_id' => 'sess-1',
                'platform' => 'web',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('access_events', [
            'visitor_id' => 'visitor-abc',
            'session_id' => 'sess-1',
            'screen' => 'complexo',
            'user_id' => null,
            'ip' => '203.0.113.10',
        ]);
    }

    public function test_logged_visit_is_linked_to_user_and_role(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CLIENTE]);

        $this->actingAs($user)
            ->postJson('/api/v1/analytics/track', [
                'event_type' => 'page_view',
                'screen' => 'agendamentos',
                'visitor_id' => 'visitor-xyz',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('access_events', [
            'user_id' => $user->id,
            'user_role' => User::ROLE_CLIENTE,
            'screen' => 'agendamentos',
        ]);
    }

    public function test_overview_requires_authentication(): void
    {
        $this->getJson('/api/v1/analytics/overview')->assertStatus(401);
    }

    public function test_cliente_cannot_access_overview(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CLIENTE]);

        $this->actingAs($cliente)
            ->getJson('/api/v1/analytics/overview')
            ->assertStatus(403);
    }

    public function test_admin_overview_counts_anonymous_and_logged_visitors(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $venue = $this->makeVenue($admin);

        AccessEvent::create(['venue_id' => $venue->id, 'visitor_id' => 'a', 'session_id' => 's1', 'event_type' => 'page_view', 'screen' => 'complexo', 'platform' => 'web', 'ip' => '1.1.1.1']);
        AccessEvent::create(['venue_id' => $venue->id, 'visitor_id' => 'b', 'session_id' => 's2', 'event_type' => 'page_view', 'screen' => 'complexo', 'platform' => 'web', 'ip' => '1.1.1.2']);
        AccessEvent::create(['venue_id' => $venue->id, 'user_id' => $admin->id, 'user_role' => User::ROLE_ADMIN, 'session_id' => 's3', 'event_type' => 'page_view', 'screen' => 'gestao', 'platform' => 'web', 'ip' => '1.1.1.3']);

        $this->actingAs($admin)
            ->getJson('/api/v1/analytics/overview?period=month')
            ->assertStatus(200)
            ->assertJsonPath('totals.anonymous_visitors', 2)
            ->assertJsonPath('totals.logged_visitors', 1)
            ->assertJsonPath('totals.unique_visitors', 3)
            ->assertJsonPath('totals.anonymous_sessions', 2)
            ->assertJsonPath('totals.logged_sessions', 1)
            ->assertJsonPath('totals.events', 3)
            ->assertJsonPath('scope', 'global');
    }

    public function test_multiple_pages_in_same_session_count_as_one_anonymous_access(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $venue = $this->makeVenue($admin);

        // 3 telas do MESMO visitante na MESMA entrada (sessão) = 1 acesso.
        foreach (['complexo', 'quadra-slots', 'agendamentos'] as $screen) {
            AccessEvent::create([
                'venue_id' => $venue->id,
                'visitor_id' => 'same-visitor',
                'session_id' => 'same-session',
                'event_type' => 'page_view',
                'screen' => $screen,
                'ip' => '9.9.9.9',
            ]);
        }

        // Nova entrada (nova sessão) do mesmo dispositivo = novo acesso.
        AccessEvent::create([
            'venue_id' => $venue->id,
            'visitor_id' => 'same-visitor',
            'session_id' => 'second-session',
            'event_type' => 'page_view',
            'screen' => 'complexo',
            'ip' => '9.9.9.9',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/analytics/overview?period=month')
            ->assertStatus(200)
            // 2 entradas (sessões) mesmo sendo 1 único dispositivo.
            ->assertJsonPath('totals.anonymous_sessions', 2)
            ->assertJsonPath('totals.anonymous_visitors', 1)
            // 4 visualizações de tela no total.
            ->assertJsonPath('totals.events', 4);
    }

    public function test_gestor_overview_is_scoped_to_own_venues(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $other = User::factory()->create(['role' => User::ROLE_GESTOR]);

        $own = $this->makeVenue($gestor, ['name' => 'Meu Complexo']);
        $foreign = $this->makeVenue($other, ['name' => 'Outro Complexo']);

        AccessEvent::create(['venue_id' => $own->id, 'visitor_id' => 'own-1', 'session_id' => 's1', 'event_type' => 'page_view', 'ip' => '1.1.1.1']);
        AccessEvent::create(['venue_id' => $foreign->id, 'visitor_id' => 'foreign-1', 'session_id' => 's2', 'event_type' => 'page_view', 'ip' => '2.2.2.2']);

        $this->actingAs($gestor)
            ->getJson('/api/v1/analytics/overview?period=month')
            ->assertStatus(200)
            ->assertJsonPath('totals.anonymous_visitors', 1)
            ->assertJsonPath('totals.events', 1)
            ->assertJsonPath('scope', 'managed');
    }

    public function test_venue_overview_forbidden_for_non_owner(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $other = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $foreign = $this->makeVenue($other);

        $this->actingAs($gestor)
            ->getJson("/api/v1/venues/{$foreign->id}/analytics")
            ->assertStatus(403);
    }

    public function test_venue_overview_allows_owner(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $venue = $this->makeVenue($gestor);

        AccessEvent::create(['venue_id' => $venue->id, 'visitor_id' => 'x', 'session_id' => 's1', 'event_type' => 'page_view', 'ip' => '1.1.1.1']);

        $this->actingAs($gestor)
            ->getJson("/api/v1/venues/{$venue->id}/analytics?period=month")
            ->assertStatus(200)
            ->assertJsonPath('scope', 'venue')
            ->assertJsonPath('totals.anonymous_visitors', 1);
    }
}
