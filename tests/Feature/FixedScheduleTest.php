<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\OperatingHour;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedScheduleTest extends TestCase
{
    use RefreshDatabase;

    private function setupCourt(User $gestor): Court
    {
        $venue = Venue::create(['user_id' => $gestor->id, 'name' => 'Arena', 'address' => 'R', 'city' => 'SP', 'state' => 'SP']);
        $court = Court::create(['venue_id' => $venue->id, 'name' => 'Q1', 'type' => 'society', 'hourly_rate' => 100]);
        foreach ([0, 1, 2, 3, 4, 5, 6] as $d) {
            OperatingHour::create(['court_id' => $court->id, 'day_of_week' => $d, 'start_time' => '08:00', 'end_time' => '22:00', 'slot_duration_minutes' => 60]);
        }
        return $court;
    }

    private function nextWeekday(int $dow): Carbon
    {
        $d = Carbon::today();
        while ((int) $d->dayOfWeek !== $dow) {
            $d->addDay();
        }
        if ($d->isToday()) {
            $d->addWeek();
        }
        return $d->startOfDay();
    }

    public function test_fixed_flow(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $court = $this->setupCourt($gestor);

        // Segunda 19:00-20:00
        $validFrom = Carbon::today()->format('Y-m-d');
        $r = $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/fixed-schedules", [
            'day_of_week' => 1,
            'start_time' => '19:00',
            'end_time' => '20:00',
            'customer_name' => 'Time do João',
            'customer_phone' => '11999990000',
            'valid_from' => $validFrom,
        ]);
        $r->assertStatus(201);
        $fixedId = $r->json('data.id');
        $this->assertNotNull($fixedId);

        $monday = $this->nextWeekday(1)->format('Y-m-d');

        // available-slots marca como fixo indisponível
        $slots = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$monday}&include_past=1");
        $slots->assertStatus(200);
        $slot19 = collect($slots->json('slots'))->firstWhere('start_time', "{$monday} 19:00:00");
        $this->assertNotNull($slot19, 'slot 19h deve existir na grade');
        $this->assertFalse((bool) $slot19['available']);
        $this->assertTrue((bool) $slot19['is_fixed']);
        $this->assertEquals($fixedId, $slot19['fixed_schedule_id']);

        // Cliente não consegue reservar o horário fixo
        $cliente = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $bk = $this->actingAs($cliente)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$monday} 19:00:00",
            'end_time' => "{$monday} 20:00:00",
        ]);
        $bk->assertStatus(422);

        // Gestor desmarca SÓ este dia -> libera para avulso
        $skip = $this->actingAs($gestor)->postJson("/api/v1/fixed-schedules/{$fixedId}/exceptions", [
            'date' => $monday,
            'reason' => 'Feriado / folga',
        ]);
        $skip->assertStatus(201);

        $slots2 = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$monday}&include_past=1");
        $slot19b = collect($slots2->json('slots'))->firstWhere('start_time', "{$monday} 19:00:00");
        $this->assertTrue((bool) $slot19b['available']);
        $this->assertFalse((bool) ($slot19b['is_fixed'] ?? false));

        // Agora o cliente consegue reservar o dia desmarcado
        $bk2 = $this->actingAs($cliente)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$monday} 19:00:00",
            'end_time' => "{$monday} 20:00:00",
        ]);
        $bk2->assertStatus(201);

        // Mas a série continua: próxima segunda segue fixa
        $nextMonday = Carbon::parse($monday)->addWeek()->format('Y-m-d');
        $slots3 = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$nextMonday}&include_past=1");
        $slot19c = collect($slots3->json('slots'))->firstWhere('start_time', "{$nextMonday} 19:00:00");
        $this->assertFalse((bool) $slot19c['available']);
        $this->assertTrue((bool) $slot19c['is_fixed']);

        // Excluir a série libera tudo
        $del = $this->actingAs($gestor)->deleteJson("/api/v1/fixed-schedules/{$fixedId}");
        $del->assertStatus(200);

        $slots4 = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$nextMonday}&include_past=1");
        $slot19d = collect($slots4->json('slots'))->firstWhere('start_time', "{$nextMonday} 19:00:00");
        $this->assertTrue((bool) $slot19d['available']);
    }

    public function test_launch_day_with_payment(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $court = $this->setupCourt($gestor);

        $validFrom = Carbon::today()->format('Y-m-d');
        $fixedId = $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/fixed-schedules", [
            'day_of_week' => 1,
            'start_time' => '19:00',
            'end_time' => '20:00',
            'customer_name' => 'Time do João',
            'valid_from' => $validFrom,
        ])->assertStatus(201)->json('data.id');

        $monday = $this->nextWeekday(1)->format('Y-m-d');

        // Gestor lança o dia como agendamento normal, com pagamento parcial
        $launch = $this->actingAs($gestor)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$monday} 19:00:00",
            'end_time' => "{$monday} 20:00:00",
            'is_manual' => true,
            'customer_name' => 'Time do João',
            'paid_amount' => 40,
            'fixed_schedule_id' => $fixedId,
        ]);
        $launch->assertStatus(201);
        $booking = $launch->json('booking');
        $this->assertEquals($fixedId, $booking['court_fixed_schedule_id']);
        $this->assertEquals('pendente', $booking['payment_status']);

        // O dia lançado aparece na lista do gestor com o vínculo
        $list = $this->actingAs($gestor)->getJson("/api/v1/bookings?date={$monday}");
        $list->assertStatus(200);
        $found = collect($list->json('data'))->firstWhere('court_fixed_schedule_id', $fixedId);
        $this->assertNotNull($found);

        // Série continua: próxima segunda segue fixa e sem booking vinculado
        $nextMonday = Carbon::parse($monday)->addWeek()->format('Y-m-d');
        $slots = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$nextMonday}&include_past=1");
        $slot = collect($slots->json('slots'))->firstWhere('start_time', "{$nextMonday} 19:00:00");
        $this->assertFalse((bool) $slot['available']);
        $this->assertTrue((bool) $slot['is_fixed']);
    }

    public function test_cliente_cannot_bypass_fixed_with_link(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $court = $this->setupCourt($gestor);

        $fixedId = $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/fixed-schedules", [
            'day_of_week' => 1,
            'start_time' => '19:00',
            'end_time' => '20:00',
            'customer_name' => 'Time do João',
            'valid_from' => Carbon::today()->format('Y-m-d'),
        ])->assertStatus(201)->json('data.id');

        $monday = $this->nextWeekday(1)->format('Y-m-d');
        $cliente = User::factory()->create(['role' => User::ROLE_CLIENTE]);

        // Cliente tenta usar o vínculo para furar o fixo -> rejeitado
        $this->actingAs($cliente)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$monday} 19:00:00",
            'end_time' => "{$monday} 20:00:00",
            'fixed_schedule_id' => $fixedId,
        ])->assertStatus(422);

        // Vínculo com data errada também é rejeitado (gestor)
        $tuesday = Carbon::parse($monday)->addDay()->format('Y-m-d');
        $this->actingAs($gestor)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$tuesday} 19:00:00",
            'end_time' => "{$tuesday} 20:00:00",
            'is_manual' => true,
            'customer_name' => 'X',
            'fixed_schedule_id' => $fixedId,
        ])->assertStatus(422);
    }

    public function test_fixed_overlap_rejected(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $court = $this->setupCourt($gestor);
        $validFrom = Carbon::today()->format('Y-m-d');

        $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/fixed-schedules", [
            'day_of_week' => 1,
            'start_time' => '19:00',
            'end_time' => '20:00',
            'customer_name' => 'A',
            'valid_from' => $validFrom,
        ])->assertStatus(201);

        // Sobreposição 19:30-20:30 deve falhar
        $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/fixed-schedules", [
            'day_of_week' => 1,
            'start_time' => '19:30',
            'end_time' => '20:30',
            'customer_name' => 'B',
            'valid_from' => $validFrom,
        ])->assertStatus(422);
    }
}
