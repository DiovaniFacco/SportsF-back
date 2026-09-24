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

class BookingConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_flow_and_anti_double_booking(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $cliente1 = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $cliente2 = User::factory()->create(['role' => User::ROLE_CLIENTE]);

        $venue = Venue::create([
            'user_id' => $gestor->id,
            'name' => 'Complexo Esportivo do Sol',
            'address' => 'Av. Brasil, 500',
            'city' => 'Curitiba',
            'state' => 'PR',
        ]);

        $court = Court::create([
            'venue_id' => $venue->id,
            'name' => 'Quadra de Tênis A',
            'type' => 'tennis',
            'hourly_rate' => 100.00,
        ]);

        // Configure operating hours for Monday (day 1) from 08:00 to 18:00 (60 min slots)
        OperatingHour::create([
            'court_id' => $court->id,
            'day_of_week' => 1, // Segunda-feira
            'start_time' => '08:00',
            'end_time' => '18:00',
            'slot_duration_minutes' => 60,
        ]);

        // Find next Monday date
        $nextMonday = Carbon::now()->next(Carbon::MONDAY)->format('Y-m-d');

        // 1. Check available slots
        $slotsResponse = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$nextMonday}");
        $slotsResponse->assertStatus(200)
            ->assertJson([
                'operating' => true,
            ]);

        $this->assertNotEmpty($slotsResponse->json('slots'));

        // 2. Cliente 1 creates a booking for slot 10:00 to 11:00
        $bookingStart = "{$nextMonday} 10:00:00";
        $bookingEnd = "{$nextMonday} 11:00:00";

        $bookingResponse1 = $this->actingAs($cliente1)
            ->postJson('/api/v1/bookings', [
                'court_id' => $court->id,
                'start_time' => $bookingStart,
                'end_time' => $bookingEnd,
            ]);

        $bookingResponse1->assertStatus(201)
            ->assertJson([
                'booking' => [
                    'court_id' => $court->id,
                    'user_id' => $cliente1->id,
                    'total_price' => 100.00,
                    'status' => 'confirmed',
                ],
            ]);

        // 3. Cliente 2 tries to book the SAME slot (10:00 to 11:00) -> DOUBLE BOOKING BLOCKED
        $bookingResponse2 = $this->actingAs($cliente2)
            ->postJson('/api/v1/bookings', [
                'court_id' => $court->id,
                'start_time' => $bookingStart,
                'end_time' => $bookingEnd,
            ]);

        $bookingResponse2->assertStatus(422)
            ->assertJsonValidationErrors(['start_time']);

        // 4. Verify that 10:00 to 11:00 slot is now marked as available = false in available-slots
        $updatedSlotsResponse = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$nextMonday}");
        $slots = $updatedSlotsResponse->json('slots');

        $slot10 = collect($slots)->firstWhere('formatted_time', '10:00 - 11:00');
        $this->assertNotNull($slot10);
        $this->assertFalse($slot10['available']);

        // 5. Cliente 1 can view and cancel their booking
        $bookingId = $bookingResponse1->json('booking.id');
        $cancelResponse = $this->actingAs($cliente1)
            ->patchJson("/api/v1/bookings/{$bookingId}/cancel");

        $cancelResponse->assertStatus(200)
            ->assertJson([
                'booking' => [
                    'id' => $bookingId,
                    'status' => 'cancelled',
                ],
            ]);

        // 6. O slot voltou a ficar disponível após o cancelamento
        $slotsAfterCancel = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$nextMonday}");
        $slotsAfterCancel->assertStatus(200);
        $slot10After = collect($slotsAfterCancel->json('slots'))->firstWhere('formatted_time', '10:00 - 11:00');
        $this->assertNotNull($slot10After);
        $this->assertTrue($slot10After['available'], 'O horário 10:00-11:00 deveria estar disponível após o cancelamento');

        // 7. Outro cliente pode reservar o mesmo horário depois do cancelamento
        $bookingResponse3 = $this->actingAs($cliente2)
            ->postJson('/api/v1/bookings', [
                'court_id' => $court->id,
                'start_time' => $bookingStart,
                'end_time' => $bookingEnd,
            ]);
        $bookingResponse3->assertStatus(201);
    }
}
