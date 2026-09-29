<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\OperatingHour;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grade (slot_duration_minutes) é só intervalo de exibição.
 * O gestor pode lançar durações livres (ex: 1h30 numa grade de 60min);
 * o cliente app continua preso à grade.
 */
class FlexibleDurationTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourt(User $gestor, float $rate = 100.00): Court
    {
        $venue = Venue::create([
            'user_id' => $gestor->id,
            'name' => 'Arena Flex',
            'address' => 'Rua F, 1',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);

        $court = Court::create([
            'venue_id' => $venue->id,
            'name' => 'Quadra Flex',
            'type' => 'society',
            'hourly_rate' => $rate,
        ]);

        foreach (range(0, 6) as $dow) {
            OperatingHour::create([
                'court_id' => $court->id,
                'day_of_week' => $dow,
                'start_time' => '08:00',
                'end_time' => '22:00',
                'slot_duration_minutes' => 60,
            ]);
        }

        return $court;
    }

    public function test_manual_booking_with_non_multiple_duration_succeeds(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $court = $this->makeCourt($gestor);
        $date = Carbon::now()->addDays(5)->format('Y-m-d');

        $res = $this->actingAs($gestor)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$date} 08:00:00",
            'end_time' => "{$date} 09:30:00",
            'is_manual' => true,
            'customer_name' => 'Cliente Balcão',
        ]);

        $res->assertStatus(201);
        // Preço proporcional: 100/h × 1.5h
        $this->assertEquals(150.00, (float) $res->json('booking.total_price'));
    }

    public function test_flexible_booking_blocks_overlapping_display_slots(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $court = $this->makeCourt($gestor);
        $date = Carbon::now()->addDays(5)->format('Y-m-d');

        $this->actingAs($gestor)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$date} 08:00:00",
            'end_time' => "{$date} 09:30:00",
            'is_manual' => true,
            'customer_name' => 'Cliente Balcão',
        ])->assertStatus(201);

        // Reserva 09:00-10:00 sobrepõe a de 08:00-09:30 -> 422
        $this->actingAs($gestor)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$date} 09:00:00",
            'end_time' => "{$date} 10:00:00",
            'is_manual' => true,
            'customer_name' => 'Outro Cliente',
        ])->assertStatus(422);

        // Grade marca os dois slots como indisponíveis
        $slots = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$date}")
            ->assertOk()
            ->json('slots');

        $byStart = collect($slots)->keyBy(fn ($s) => substr($s['start_time'], 11, 5));
        $this->assertFalse((bool) $byStart['08:00']['available']);
        $this->assertFalse((bool) $byStart['09:00']['available']);
        $this->assertTrue((bool) $byStart['10:00']['available']);
    }

    public function test_client_booking_keeps_strict_grid_alignment(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $cliente = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $court = $this->makeCourt($gestor);
        $date = Carbon::now()->addDays(5)->format('Y-m-d');

        $this->actingAs($cliente)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$date} 08:15:00",
            'end_time' => "{$date} 09:15:00",
        ])->assertStatus(422);
    }

    public function test_manual_booking_below_minimum_duration_fails(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $court = $this->makeCourt($gestor);
        $date = Carbon::now()->addDays(5)->format('Y-m-d');

        $this->actingAs($gestor)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$date} 08:00:00",
            'end_time' => "{$date} 08:10:00",
            'is_manual' => true,
            'customer_name' => 'Cliente Balcão',
        ])->assertStatus(422);
    }

    public function test_manual_booking_must_stay_within_single_shift(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $venue = Venue::create([
            'user_id' => $gestor->id,
            'name' => 'Arena Turnos',
            'address' => 'Rua T, 1',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);
        $court = Court::create([
            'venue_id' => $venue->id,
            'name' => 'Quadra Turnos',
            'type' => 'society',
            'hourly_rate' => 100.00,
        ]);
        foreach (range(0, 6) as $dow) {
            OperatingHour::create([
                'court_id' => $court->id, 'day_of_week' => $dow,
                'start_time' => '08:00', 'end_time' => '12:00', 'slot_duration_minutes' => 60,
            ]);
            OperatingHour::create([
                'court_id' => $court->id, 'day_of_week' => $dow,
                'start_time' => '14:00', 'end_time' => '22:00', 'slot_duration_minutes' => 60,
            ]);
        }
        $date = Carbon::now()->addDays(5)->format('Y-m-d');

        // Segundo turno: duração livre OK
        $this->actingAs($gestor)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$date} 14:00:00",
            'end_time' => "{$date} 15:30:00",
            'is_manual' => true,
            'customer_name' => 'Tarde',
        ])->assertStatus(201);

        // Atravessando o intervalo entre turnos -> 422
        $this->actingAs($gestor)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$date} 11:30:00",
            'end_time' => "{$date} 14:30:00",
            'is_manual' => true,
            'customer_name' => 'Almoço',
        ])->assertStatus(422);
    }
}
