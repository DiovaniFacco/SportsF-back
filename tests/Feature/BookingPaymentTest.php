<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\OperatingHour;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function makeCourt(User $gestor, float $rate = 100.00): array
    {
        $venue = Venue::create([
            'user_id' => $gestor->id,
            'name' => 'Arena Pagamento',
            'address' => 'Rua P, 1',
            'city' => 'São Paulo',
            'state' => 'SP',
        ]);

        $court = Court::create([
            'venue_id' => $venue->id,
            'name' => 'Quadra Pagamento',
            'type' => 'society',
            'hourly_rate' => $rate,
        ]);

        // Turno todos os dias 08:00-22:00, slots de 60min
        foreach (range(0, 6) as $dow) {
            OperatingHour::create([
                'court_id' => $court->id,
                'day_of_week' => $dow,
                'start_time' => '08:00',
                'end_time' => '22:00',
                'slot_duration_minutes' => 60,
            ]);
        }

        $date = Carbon::now()->addDays(5)->format('Y-m-d');

        return [$court, $date];
    }

    private function manualBookingPayload(Court $court, string $date, $paidAmount): array
    {
        return [
            'court_id' => $court->id,
            'start_time' => "{$date} 10:00:00",
            'end_time' => "{$date} 11:00:00",
            'is_manual' => true,
            'customer_name' => 'Cliente Balcão',
            'paid_amount' => $paidAmount,
        ];
    }

    public function test_manual_booking_partial_payment_sets_pendente(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        [$court, $date] = $this->makeCourt($gestor);

        $res = $this->actingAs($gestor)->postJson(
            '/api/v1/bookings',
            $this->manualBookingPayload($court, $date, 40)
        );

        $res->assertStatus(201);
        $this->assertEquals('pendente', $res->json('booking.payment_status'));
        $this->assertEquals(40.00, (float) $res->json('booking.paid_amount'));
        $this->assertEquals(100.00, (float) $res->json('booking.total_price'));
        $this->assertFalse((bool) $res->json('booking.is_paid'));
    }

    public function test_manual_booking_full_payment_sets_pago(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        [$court, $date] = $this->makeCourt($gestor);

        $res = $this->actingAs($gestor)->postJson(
            '/api/v1/bookings',
            $this->manualBookingPayload($court, $date, 100)
        );

        $res->assertStatus(201);
        $this->assertEquals('pago', $res->json('booking.payment_status'));
        $this->assertTrue((bool) $res->json('booking.is_paid'));
    }

    public function test_create_rejects_overpayment(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        [$court, $date] = $this->makeCourt($gestor);

        $res = $this->actingAs($gestor)->postJson(
            '/api/v1/bookings',
            $this->manualBookingPayload($court, $date, 1000)
        );

        $res->assertStatus(422);
        $this->assertArrayHasKey('paid_amount', $res->json('errors', []));
    }

    public function test_update_payment_partial_then_rejects_overpayment(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        [$court, $date] = $this->makeCourt($gestor);

        $created = $this->actingAs($gestor)->postJson(
            '/api/v1/bookings',
            $this->manualBookingPayload($court, $date, 0)
        )->assertStatus(201);
        $id = $created->json('booking.id');

        $partial = $this->actingAs($gestor)->patchJson("/api/v1/bookings/{$id}/payment", [
            'paid_amount' => 40,
        ]);
        $partial->assertStatus(200);
        $this->assertEquals('pendente', $partial->json('booking.payment_status'));

        $over = $this->actingAs($gestor)->patchJson("/api/v1/bookings/{$id}/payment", [
            'paid_amount' => 500,
        ]);
        $over->assertStatus(422);
        $this->assertArrayHasKey('paid_amount', $over->json('errors', []));
    }

    public function test_cancel_zeroes_payment(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        [$court, $date] = $this->makeCourt($gestor);

        $created = $this->actingAs($gestor)->postJson(
            '/api/v1/bookings',
            $this->manualBookingPayload($court, $date, 100)
        )->assertStatus(201);
        $id = $created->json('booking.id');

        // Usa data futura distante para passar em qualquer regra de antecedência
        $cancel = $this->actingAs($gestor)->patchJson("/api/v1/bookings/{$id}/cancel");
        $cancel->assertStatus(200);

        $booking = \App\Models\Booking::withTrashed()->find($id);
        $this->assertEquals('cancelled', $booking->status);
        $this->assertEquals(0, (float) $booking->paid_amount);
        $this->assertEquals('nao_pago', $booking->payment_status);
        $this->assertFalse((bool) $booking->is_paid);
    }

    public function test_batch_rejects_paid_amount(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        [$court, $date] = $this->makeCourt($gestor);

        $res = $this->actingAs($gestor)->postJson('/api/v1/bookings/batch', [
            'court_id' => $court->id,
            'slots' => [
                ['start_time' => "{$date} 10:00:00", 'end_time' => "{$date} 11:00:00"],
                ['start_time' => "{$date} 11:00:00", 'end_time' => "{$date} 12:00:00"],
            ],
            'paid_amount' => 100,
        ]);

        $res->assertStatus(422);
        $this->assertArrayHasKey('paid_amount', $res->json('errors', []));
    }
}
