<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Court;
use App\Models\OperatingHour;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cobertura das correções QA (B1, B3, B7, event_date, is_past, register).
 */
class QaFixesTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourtWithMondayHours(User $gestor): Court
    {
        $venue = Venue::create([
            'user_id' => $gestor->id,
            'name' => 'Arena QA',
            'address' => 'Rua QA, 1',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);

        $court = Court::create([
            'venue_id' => $venue->id,
            'name' => 'Quadra QA',
            'type' => 'society',
            'hourly_rate' => 100.00,
        ]);

        OperatingHour::create([
            'court_id' => $court->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '22:00',
            'slot_duration_minutes' => 60,
        ]);

        return $court;
    }

    private function nextMonday(): string
    {
        return Carbon::now()->addDays(5)->next(Carbon::MONDAY)->format('Y-m-d');
    }

    public function test_operating_hours_rejects_inverted_shift(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $court = $this->makeCourtWithMondayHours($gestor);

        $response = $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/operating-hours", [
            'day_of_week' => 2,
            'start_time' => '22:00',
            'end_time' => '08:00',
            'slot_duration_minutes' => 60,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseMissing('operating_hours', [
            'court_id' => $court->id,
            'day_of_week' => 2,
        ]);
    }

    public function test_operating_hours_rejects_garbage_format(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $court = $this->makeCourtWithMondayHours($gestor);

        $response = $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/operating-hours", [
            'day_of_week' => 0,
            'start_time' => 'abc',
            'end_time' => 'xyz',
            'slot_duration_minutes' => 60,
        ]);

        // 422 de validação — nunca 500 com stack trace.
        $response->assertStatus(422);
    }

    public function test_operating_hours_accepts_valid_shift(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $court = $this->makeCourtWithMondayHours($gestor);

        $response = $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/operating-hours", [
            'day_of_week' => 6,
            'start_time' => '08:00',
            'end_time' => '12:00',
            'slot_duration_minutes' => 60,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
        $this->assertDatabaseHas('operating_hours', [
            'court_id' => $court->id,
            'day_of_week' => 6,
        ]);
    }

    public function test_batch_booking_is_atomic_on_conflict(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $cliente = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $outro = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $court = $this->makeCourtWithMondayHours($gestor);
        $date = $this->nextMonday();

        // Ocupa o segundo slot com outro cliente.
        $this->actingAs($outro)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$date} 11:00:00",
            'end_time' => "{$date} 12:00:00",
        ])->assertStatus(201);

        // Lote com 1 slot livre + 1 ocupado deve falhar por inteiro.
        $response = $this->actingAs($cliente)->postJson('/api/v1/bookings/batch', [
            'court_id' => $court->id,
            'slots' => [
                ['start_time' => "{$date} 10:00:00", 'end_time' => "{$date} 11:00:00"],
                ['start_time' => "{$date} 11:00:00", 'end_time' => "{$date} 12:00:00"],
            ],
        ]);

        $response->assertStatus(422);
        // Sucesso parcial seria bug: o slot livre NÃO pode ter sido criado.
        $this->assertDatabaseMissing('bookings', [
            'court_id' => $court->id,
            'user_id' => $cliente->id,
            'start_time' => "{$date} 10:00:00",
        ]);
    }

    public function test_batch_booking_success_creates_all(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $cliente = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $court = $this->makeCourtWithMondayHours($gestor);
        $date = $this->nextMonday();

        $response = $this->actingAs($cliente)->postJson('/api/v1/bookings/batch', [
            'court_id' => $court->id,
            'slots' => [
                ['start_time' => "{$date} 10:00:00", 'end_time' => "{$date} 11:00:00"],
                ['start_time' => "{$date} 11:00:00", 'end_time' => "{$date} 12:00:00"],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonCount(2, 'bookings');
        $this->assertEquals(2, Booking::where('user_id', $cliente->id)->count());
    }

    public function test_special_price_returns_date_only(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $court = $this->makeCourtWithMondayHours($gestor);

        $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/special-prices", [
            'event_date' => '2026-12-25',
            'hourly_rate' => 250,
            'label' => 'Natal',
        ])->assertStatus(201);

        $response = $this->actingAs($gestor)->getJson("/api/v1/courts/{$court->id}/special-prices");
        $response->assertStatus(200);
        $eventDate = $response->json('special_prices.0.event_date');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $eventDate);
    }

    public function test_available_slots_flags_past_date(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $court = $this->makeCourtWithMondayHours($gestor);
        $pastMonday = Carbon::now()->subDays(10)->previous(Carbon::MONDAY)->format('Y-m-d');

        $response = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$pastMonday}");

        $response->assertStatus(200)
            ->assertJson(['is_past' => true]);
    }

    public function test_api_404_is_clean_json_without_trace(): void
    {
        $response = $this->getJson('/api/v1/venues/999999');

        $response->assertStatus(404)
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('exception');
    }

    public function test_register_ignores_role_and_forces_cliente(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Tentando Gestor',
            'email' => 'quero-ser-gestor@qa.test',
            'password' => 'senha123456',
            'password_confirmation' => 'senha123456',
            'role' => User::ROLE_GESTOR,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.role', User::ROLE_CLIENTE);
    }
}
