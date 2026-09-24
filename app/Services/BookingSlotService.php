<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlockout;
use App\Models\CourtPricingRule;
use App\Models\CourtSpecialPrice;
use App\Models\OperatingHour;
use Carbon\Carbon;

class BookingSlotService
{
    /**
     * Get all generated time slots for a given court and date, marking availability and calculating dynamic prices.
     *
     * @param Court $court
     * @param string $dateStr (Format YYYY-MM-DD)
     * @return array
     */
    public function getAvailableSlotsForCourt(Court $court, string $dateStr, bool $includePast = false): array
    {
        $date = Carbon::parse($dateStr);
        $dayOfWeek = $date->dayOfWeek; // 0 = Sunday, 6 = Saturday

        // Data no passado: sinaliza explicitamente para o app exibir
        // mensagem adequada (em vez de "sem horários cadastrados").
        $isPast = $date->isBefore(Carbon::today());

        $operatingHours = OperatingHour::where('court_id', $court->id)
            ->where('day_of_week', $dayOfWeek)
            ->orderBy('start_time')
            ->get();

        if ($operatingHours->isEmpty()) {
            return [
                'court_id' => $court->id,
                'date' => $dateStr,
                'day_of_week' => $dayOfWeek,
                'operating' => false,
                'is_past' => $isPast,
                'slots' => [],
            ];
        }

        // Find earliest start time and latest end time across shifts to query bookings/blockouts
        $earliestStartStr = $operatingHours->min('start_time');
        $latestEndStr = $operatingHours->max('end_time');

        $overallStart = Carbon::parse($dateStr . ' ' . $earliestStartStr);
        $overallEnd = Carbon::parse($dateStr . ' ' . $latestEndStr);

        // Se houver turno que cruza a meia-noite (end_time <= start_time ou overallEnd <= overallStart), estende overallEnd por 1 dia
        $hasOvernight = $operatingHours->contains(fn($op) => $op->end_time <= $op->start_time);
        if ($hasOvernight || $overallEnd->lte($overallStart)) {
            $overallEnd->addDay();
        }

        // Fetch all confirmed bookings for this court on this day
        $confirmedBookings = Booking::where('court_id', $court->id)
            ->where('status', Booking::STATUS_CONFIRMED)
            ->where('start_time', '<', $overallEnd)
            ->where('end_time', '>', $overallStart)
            ->get();

        // Fetch all active blockouts for this court on this day
        // (inclui indeterminados que começaram antes do fim do dia consultado)
        $blockouts = CourtBlockout::where('court_id', $court->id)
            ->overlapping($overallStart, $overallEnd)
            ->get();

        // Bloqueio indeterminado ativo para a data consultada:
        // usuário comum nem deve ver os horários — retorna aviso.
        $indefiniteBlockout = $blockouts
            ->first(fn(CourtBlockout $b) => $b->isIndefinite() && $b->start_time->lte($overallEnd));

        if ($indefiniteBlockout) {
            return [
                'court_id' => $court->id,
                'court_name' => $court->name,
                'date' => $dateStr,
                'day_of_week' => $dayOfWeek,
                'operating' => true,
                'is_past' => $isPast,
                'blocked_indefinitely' => true,
                'is_blocked_indefinitely' => true,
                'block_reason' => $indefiniteBlockout->reason,
                'blockout' => $indefiniteBlockout,
                'message' => 'Esta quadra está temporariamente indisponível'
                    . ($indefiniteBlockout->reason ? " ({$indefiniteBlockout->reason})" : '')
                    . '. Tente outra quadra ou data.',
                'slots' => [],
            ];
        }

        // Preload pricing rules for this court
        $specialPrice = CourtSpecialPrice::where('court_id', $court->id)
            ->whereDate('event_date', $dateStr)
            ->first();

        $pricingRules = CourtPricingRule::where('court_id', $court->id)
            ->where('day_of_week', $dayOfWeek)
            ->get();

        $slots = [];
        $now = now();

        foreach ($operatingHours as $operatingHour) {
            $slotDuration = $operatingHour->slot_duration_minutes ?? 60;
            $shiftStart = Carbon::parse($dateStr . ' ' . $operatingHour->start_time);
            $shiftEnd = Carbon::parse($dateStr . ' ' . $operatingHour->end_time);

            // Suporte a turnos noturnos que cruzam a meia-noite (ex: 22:00 às 02:00)
            if ($shiftEnd->lte($shiftStart)) {
                $shiftEnd->addDay();
            }

            $currentStart = $shiftStart->copy();

            while ($currentStart->copy()->addMinutes($slotDuration)->lte($shiftEnd)) {
                $currentEnd = $currentStart->copy()->addMinutes($slotDuration);

                // Skip slots that have already passed if not explicitly requested
                if (!$includePast && $currentStart->lt($now)) {
                    $currentStart->addMinutes($slotDuration);
                    continue;
                }

                // Check if this slot overlaps with any confirmed booking
                $isBooked = $confirmedBookings->contains(function (Booking $booking) use ($currentStart, $currentEnd) {
                    return $currentStart->lt($booking->end_time) && $currentEnd->gt($booking->start_time);
                });

                // Check if this slot overlaps with any blockout
                // (indeterminado: end_time NULL bloqueia tudo após start_time)
                $activeBlockout = $blockouts->first(function (CourtBlockout $blockout) use ($currentStart, $currentEnd) {
                    return $blockout->overlaps($currentStart, $currentEnd);
                });

                $isBlocked = $activeBlockout !== null;

                $hourlyRate = $this->determineHourlyRate($court, $dateStr, $dayOfWeek, $currentStart, $currentEnd, $specialPrice, $pricingRules);
                $price = round(($hourlyRate * $slotDuration) / 60, 2);

                $slots[] = [
                    'start_time' => $currentStart->format('Y-m-d H:i:s'),
                    'end_time' => $currentEnd->format('Y-m-d H:i:s'),
                    'formatted_time' => $currentStart->format('H:i') . ' - ' . $currentEnd->format('H:i'),
                    'price' => $price,
                    'available' => !$isBooked && !$isBlocked,
                    'blocked' => $isBlocked,
                    'block_reason' => $activeBlockout?->reason,
                ];

                $currentStart->addMinutes($slotDuration);
            }
        }

        return [
            'court_id' => $court->id,
            'court_name' => $court->name,
            'date' => $dateStr,
            'day_of_week' => $dayOfWeek,
            'operating' => true,
            'is_past' => $isPast,
            'message' => $isPast && empty($slots)
                ? 'Esta data já passou. Selecione hoje ou uma data futura.'
                : null,
            'blocked_indefinitely' => false,
            'is_blocked_indefinitely' => false,
            'slots' => $slots,
        ];
    }

    /**
     * Calculate hourly rate based on priority: Special Date > Peak Hours Rule > Court Base Rate.
     */
    public function determineHourlyRate(
        Court $court,
        string $dateStr,
        int $dayOfWeek,
        Carbon $startTime,
        Carbon $endTime,
        ?CourtSpecialPrice $specialPrice = null,
        $pricingRules = null
    ): float {
        // 1. Check special price for specific date
        if ($specialPrice === null) {
            $specialPrice = CourtSpecialPrice::where('court_id', $court->id)
                ->whereDate('event_date', $dateStr)
                ->first();
        }

        if ($specialPrice) {
            return (float) $specialPrice->hourly_rate;
        }

        // 2. Check peak hours pricing rules for day of week and time range
        if ($pricingRules === null) {
            $pricingRules = CourtPricingRule::where('court_id', $court->id)
                ->where('day_of_week', $dayOfWeek)
                ->get();
        }

        $slotStartFormatted = $startTime->format('H:i:s');
        $slotEndFormatted = $endTime->format('H:i:s');

        foreach ($pricingRules as $rule) {
            if ($slotStartFormatted >= $rule->start_time && $slotEndFormatted <= $rule->end_time) {
                return (float) $rule->hourly_rate;
            }
        }

        // 3. Fallback to court base hourly rate
        return (float) $court->hourly_rate;
    }
}
