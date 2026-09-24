<?php

namespace Tests\Feature;

use App\Models\Court;
use App\Models\OperatingHour;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndefiniteBlockoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_indefinite_flow(): void
    {
        $gestor = User::factory()->create(['role' => User::ROLE_GESTOR]);
        $venue = Venue::create(['user_id' => $gestor->id, 'name' => 'A', 'address' => 'R', 'city' => 'SP', 'state' => 'SP']);
        $court = Court::create(['venue_id' => $venue->id, 'name' => 'Q', 'type' => 'society', 'hourly_rate' => 100]);
        foreach ([0,1,2,3,4,5,6] as $d) {
            OperatingHour::create(['court_id' => $court->id, 'day_of_week' => $d, 'start_time' => '08:00', 'end_time' => '22:00', 'slot_duration_minutes' => 60]);
        }

        // 1. Cria bloqueio indeterminado sem end_time
        $r = $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/blockouts", [
            'start_time' => now()->subHour()->format('Y-m-d H:i:s'),
            'is_indefinite' => true,
            'reason' => 'Chuva / Mau Tempo',
        ]);
        $r->assertStatus(201);
        $this->assertTrue((bool) $r->json('data.is_indefinite'));
        $this->assertNull($r->json('data.end_time'));
        $blockId = $r->json('data.id');

        // 2. Usuário comum vê flag na quadra (aviso antes dos horários)
        $show = $this->getJson("/api/v1/courts/{$court->id}");
        $show->assertStatus(200);
        $this->assertTrue((bool) $show->json('is_blocked_indefinitely'));
        $this->assertNotNull($show->json('block_message'));

        // 3. available-slots retorna aviso, sem slots
        $date = now()->format('Y-m-d');
        $slots = $this->getJson("/api/v1/courts/{$court->id}/available-slots?date={$date}");
        $slots->assertStatus(200);
        $this->assertTrue((bool) $slots->json('blocked_indefinitely'));
        $this->assertCount(0, $slots->json('slots'));
        $this->assertNotEmpty($slots->json('message'));

        // 4. Booking bloqueado
        $cliente = User::factory()->create(['role' => User::ROLE_CLIENTE]);
        $tomorrow = now()->addDay()->format('Y-m-d');
        $bk = $this->actingAs($cliente)->postJson('/api/v1/bookings', [
            'court_id' => $court->id,
            'start_time' => "{$tomorrow} 10:00:00",
            'end_time' => "{$tomorrow} 11:00:00",
        ]);
        $bk->assertStatus(422);

        // 5. Desbloqueio (gestor remove) libera tudo
        $del = $this->actingAs($gestor)->deleteJson("/api/v1/blockouts/{$blockId}");
        $del->assertStatus(200);
        $show2 = $this->getJson("/api/v1/courts/{$court->id}");
        $this->assertFalse((bool) $show2->json('is_blocked_indefinitely'));

        // 6. Bloqueio normal ainda exige end_time
        $bad = $this->actingAs($gestor)->postJson("/api/v1/courts/{$court->id}/blockouts", [
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'reason' => 'x',
        ]);
        $bad->assertStatus(422);
    }
}
