<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlockout;
use App\Models\CourtPricingRule;
use App\Models\CourtSpecialPrice;
use App\Models\OperatingHour;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NewBusinessRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_bind_venue_to_gestor(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);

        $response = $this->actingAs($admin)->postJson('/api/v1/venues', [
            'user_id' => $gestor->id,
            'name' => 'Arena Criada pelo Admin',
            'address' => 'Rua Principal, 1',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'venue' => [
                    'user_id' => $gestor->id,
                    'name' => 'Arena Criada pelo Admin',
                ],
            ]);
    }

    public function test_strict_slot_alignment_and_notice_rules(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $cliente = User::factory()->create(['role' => User::ROLE_CLIENTE]);

        $venue = Venue::create([
            'user_id' => $gestor->id,
            'name' => 'Arena Teste',
            'address' => 'Rua A, 10',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);

        $court = Court::create([
            'venue_id' => $venue->id,
            'name' => 'Quadra 1',
            'type' => 'society',
            'hourly_rate' => 100.00,
            'min_notice_hours' => 2,
            'max_advance_days' => 15,
            'max_active_bookings_per_user' => 2,
        ]);

        // Monday 08:00 - 22:00, 60 min slots
        OperatingHour::create([
            'court_id' => $court->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '22:00',
            'slot_duration_minutes' => 60,
        ]);

        $targetMonday = Carbon::now()->addDays(5)->next(Carbon::MONDAY);
        $dateStr = $targetMonday->format('Y-m-d');

        // 1. Unaligned slot test (e.g. starting at 08:15 instead of 08:00) -> 422
        $unalignedResponse = $this->actingAs($cliente)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$dateStr} 08:15:00",
            'end_time' => "{$dateStr} 09:15:00",
        ]);
        $unalignedResponse->assertStatus(422);

        // 2. Minimum notice violation test (booking for 30 minutes from now) -> 422
        $soonTime = Carbon::now()->addMinutes(30)->format('Y-m-d H:i:s');
        $soonEndTime = Carbon::now()->addMinutes(90)->format('Y-m-d H:i:s');
        $noticeResponse = $this->actingAs($cliente)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => $soonTime,
            'end_time' => $soonEndTime,
        ]);
        $noticeResponse->assertStatus(422);

        // 3. Max advance days violation test (booking for 20 days ahead when max is 15) -> 422
        $farFutureMonday = Carbon::now()->addDays(25)->next(Carbon::MONDAY)->format('Y-m-d');
        $farResponse = $this->actingAs($cliente)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$farFutureMonday} 10:00:00",
            'end_time' => "{$farFutureMonday} 11:00:00",
        ]);
        $farResponse->assertStatus(422);
    }

    public function test_peak_hours_and_special_date_pricing(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);

        $venue = Venue::create([
            'user_id' => $gestor->id,
            'name' => 'Arena Pricing',
            'address' => 'Rua B, 20',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);

        $court = Court::create([
            'venue_id' => $venue->id,
            'name' => 'Quadra Pricing',
            'type' => 'society',
            'hourly_rate' => 100.00,
        ]);

        OperatingHour::create([
            'court_id' => $court->id,
            'day_of_week' => 1, // Segunda
            'start_time' => '08:00',
            'end_time' => '22:00',
            'slot_duration_minutes' => 60,
        ]);

        // Add Peak Hours Rule for Monday 18:00 - 22:00 (R$ 180/hr)
        $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/pricing-rules", [
            'day_of_week' => 1,
            'start_time' => '18:00',
            'end_time' => '22:00',
            'hourly_rate' => 180.00,
            'label' => 'Horário Nobre Noturno',
        ])->assertStatus(201);

        $targetMonday = Carbon::now()->addDays(5)->next(Carbon::MONDAY);
        $dateStr = $targetMonday->format('Y-m-d');

        // Available slots should show base price for 10:00 (100) and peak price for 18:00 (180)
        $slotsResponse = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$dateStr}");
        $slotsResponse->assertStatus(200);

        $slots = $slotsResponse->json('slots');
        $slotMorning = collect($slots)->firstWhere('formatted_time', '10:00 - 11:00');
        $slotNight = collect($slots)->firstWhere('formatted_time', '18:00 - 19:00');

        $this->assertEquals(100.00, $slotMorning['price']);
        $this->assertEquals(180.00, $slotNight['price']);

        // Add Special Date Price for specific Monday (R$ 250/hr) -> overrides everything
        $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/special-prices", [
            'event_date' => $dateStr,
            'hourly_rate' => 250.00,
            'label' => 'Feriado Nacional',
        ])->assertStatus(201);

        $specialSlotsResponse = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$dateStr}");
        $specialSlots = $specialSlotsResponse->json('slots');
        $specialSlotMorning = collect($specialSlots)->firstWhere('formatted_time', '10:00 - 11:00');
        $specialSlotNight = collect($specialSlots)->firstWhere('formatted_time', '18:00 - 19:00');

        $this->assertEquals(250.00, $specialSlotMorning['price']);
        $this->assertEquals(250.00, $specialSlotNight['price']);
    }

    public function test_court_blockout_and_manual_booking(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);

        $venue = Venue::create([
            'user_id' => $gestor->id,
            'name' => 'Arena Ops',
            'address' => 'Rua C, 30',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);

        $court = Court::create([
            'venue_id' => $venue->id,
            'name' => 'Quadra Ops',
            'type' => 'sand',
            'hourly_rate' => 80.00,
        ]);

        OperatingHour::create([
            'court_id' => $court->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '20:00',
            'slot_duration_minutes' => 60,
        ]);

        $targetMonday = Carbon::now()->addDays(5)->next(Carbon::MONDAY);
        $dateStr = $targetMonday->format('Y-m-d');

        // 1. Gestor creates Blockout for 14:00 - 16:00 (Manutenção)
        $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/blockouts", [
            'start_time' => "{$dateStr} 14:00:00",
            'end_time' => "{$dateStr} 16:00:00",
            'reason' => 'Troca de Areia',
        ])->assertStatus(201);

        // Slot 14:00 - 15:00 should be available: false and blocked: true
        $slotsResponse = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$dateStr}");
        $slot14 = collect($slotsResponse->json('slots'))->firstWhere('formatted_time', '14:00 - 15:00');
        $this->assertFalse($slot14['available']);
        $this->assertTrue($slot14['blocked']);
        $this->assertEquals('Troca de Areia', $slot14['block_reason']);

        // 2. Gestor creates Manual Walk-in Booking for 10:00 - 11:00
        $manualBookingResponse = $this->actingAs($gestor)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$dateStr} 10:00:00",
            'end_time' => "{$dateStr} 11:00:00",
            'is_manual' => true,
            'customer_name' => 'Carlos Cliente Balcão',
            'customer_phone' => '11988887777',
            'notes' => 'Pago em dinheiro no balcão',
        ]);

        $manualBookingResponse->assertStatus(201)
            ->assertJson([
                'booking' => [
                    'court_id' => $court->id,
                    'user_id' => null,
                    'customer_name' => 'Carlos Cliente Balcão',
                    'is_manual' => true,
                ],
            ]);
    }
}
