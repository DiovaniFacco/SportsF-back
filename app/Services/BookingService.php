<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Court;
use App\Models\CourtBlockout;
use App\Models\OperatingHour;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        protected BookingSlotService $bookingSlotService
    ) {}

    /**
     * Create a booking with strict validation rules and concurrency protection.
     *
     * @param User $user User performing the action (or booking owner)
     * @param int $courtId
     * @param string $startTimeStr
     * @param string $endTimeStr
     * @param array $extraParams Options for manual booking: ['is_manual' => bool, 'customer_name' => string, 'customer_phone' => string, 'notes' => string]
     * @return Booking
     * @throws ValidationException
     */
    public function createBooking(
        User $user,
        int $courtId,
        string $startTimeStr,
        string $endTimeStr,
        array $extraParams = []
    ): Booking {
        $startTime = Carbon::parse($startTimeStr);
        $endTime = Carbon::parse($endTimeStr);
        $now = now();

        if ($startTime->gte($endTime)) {
            throw ValidationException::withMessages([
                'end_time' => ['O horário de término deve ser posterior ao horário de início.'],
            ]);
        }

        if ($startTime->lt($now)) {
            throw ValidationException::withMessages([
                'start_time' => ['Não é possível agendar para um horário no passado.'],
            ]);
        }

        $isManual = $extraParams['is_manual'] ?? false;

        // If manual booking, verify that user is Gestor or Admin
        if ($isManual) {
            if (!$user->isGestor() && !$user->isAdmin()) {
                throw ValidationException::withMessages([
                    'is_manual' => ['Apenas gestores ou administradores podem realizar agendamentos manuais/presenciais.'],
                ]);
            }
            if (empty($extraParams['customer_name'])) {
                throw ValidationException::withMessages([
                    'customer_name' => ['O nome do cliente é obrigatório para agendamentos manuais de balcão.'],
                ]);
            }
        }

        return DB::transaction(function () use ($user, $courtId, $startTime, $endTime, $now, $isManual, $extraParams) {
            // 1. Lock court row pessimistically
            $court = Court::where('id', $courtId)->lockForUpdate()->first();

            if (!$court) {
                throw ValidationException::withMessages([
                    'court_id' => ['Quadra não encontrada.'],
                ]);
            }

            // If manual booking by gestor, check ownership of venue unless Admin
            if ($isManual && !$user->isAdmin() && $court->venue->user_id !== $user->id) {
                throw ValidationException::withMessages([
                    'court_id' => ['Você não tem permissão para gerenciar agendamentos nesta quadra.'],
                ]);
            }

            // 2. Validate Minimum Notice Hours (Antecedência Mínima)
            $minNoticeHours = $court->min_notice_hours ?? 1;
            if (!$isManual && $startTime->lt($now->copy()->addHours($minNoticeHours))) {
                throw ValidationException::withMessages([
                    'start_time' => ["Os agendamentos nesta quadra devem ser realizados com pelo menos {$minNoticeHours} hora(s) de antecedência."],
                ]);
            }

            // 3. Validate Maximum Advance Days (Antecedência Máxima)
            $maxAdvanceDays = $court->max_advance_days ?? 30;
            if (!$isManual && $startTime->gt($now->copy()->addDays($maxAdvanceDays))) {
                throw ValidationException::withMessages([
                    'start_time' => ["Os agendamentos só podem ser realizados com no máximo {$maxAdvanceDays} dias de antecedência."],
                ]);
            }

            // 4. Validate Maximum Active Bookings per User (Limite de Reservas Ativas por Cliente)
            if (!$isManual && $user->isCliente()) {
                $maxActiveBookings = $court->max_active_bookings_per_user ?? 3;
                $activeBookingsCount = Booking::where('user_id', $user->id)
                    ->where('status', Booking::STATUS_CONFIRMED)
                    ->where('start_time', '>=', $now)
                    ->count();

                if ($activeBookingsCount >= $maxActiveBookings) {
                    throw ValidationException::withMessages([
                        'user' => ["Você já possui {$activeBookingsCount} reserva(s) ativa(s). O limite máximo permitido é de {$maxActiveBookings} reserva(s) pendente(s)/futura(s)."],
                    ]);
                }
            }

            // 5. Validate Operating Hours
            $dayOfWeek = $startTime->dayOfWeek;
            $operatingHour = OperatingHour::where('court_id', $courtId)
                ->where('day_of_week', $dayOfWeek)
                ->first();

            if (!$operatingHour) {
                throw ValidationException::withMessages([
                    'start_time' => ['A quadra não possui horário de funcionamento cadastrado para este dia da semana.'],
                ]);
            }

            $dateStr = $startTime->format('Y-m-d');
            $opStart = Carbon::parse($dateStr . ' ' . $operatingHour->start_time);
            $opEnd = Carbon::parse($dateStr . ' ' . $operatingHour->end_time);

            if ($startTime->lt($opStart) || $endTime->gt($opEnd)) {
                throw ValidationException::withMessages([
                    'start_time' => ["O agendamento deve estar dentro do horário de funcionamento ({$operatingHour->start_time} às {$operatingHour->end_time})."],
                ]);
            }

            // 6. Validate Strict Slot Alignment (Encaixe Padronizado nos Slots)
            $slotDuration = $operatingHour->slot_duration_minutes ?? 60;
            $durationMinutes = $startTime->diffInMinutes($endTime);

            if ($durationMinutes % $slotDuration !== 0) {
                throw ValidationException::withMessages([
                    'end_time' => ["A duração do agendamento deve ser múltipla de {$slotDuration} minutos."],
                ]);
            }

            $minutesFromOpStart = $opStart->diffInMinutes($startTime, false);
            if ($minutesFromOpStart < 0 || $minutesFromOpStart % $slotDuration !== 0) {
                throw ValidationException::withMessages([
                    'start_time' => ["O horário de início do agendamento deve ser alinhado com a grade de slots da quadra (a cada {$slotDuration} minutos a partir de {$operatingHour->start_time})."],
                ]);
            }

            // 7. Validate Court Blockouts (Bloqueio de Agenda por Manutenção/Eventos)
            $hasBlockout = CourtBlockout::where('court_id', $courtId)
                ->where('start_time', '<', $endTime)
                ->where('end_time', '>', $startTime)
                ->first();

            if ($hasBlockout) {
                $reasonText = $hasBlockout->reason ? " Motivo: {$hasBlockout->reason}" : '';
                throw ValidationException::withMessages([
                    'start_time' => ["Este horário está bloqueado pelo gestor para manutenção ou evento.{$reasonText}"],
                ]);
            }

            // 8. Strict anti-double booking check
            $hasOverlap = Booking::where('court_id', $courtId)
                ->where('status', Booking::STATUS_CONFIRMED)
                ->where('start_time', '<', $endTime)
                ->where('end_time', '>', $startTime)
                ->lockForUpdate()
                ->exists();

            if ($hasOverlap) {
                throw ValidationException::withMessages([
                    'start_time' => ['Este horário já se encontra reservado por outro cliente (Double booking bloqueado).'],
                ]);
            }

            // 9. Calculate Dynamic Total Price
            $hourlyRate = $this->bookingSlotService->determineHourlyRate(
                $court,
                $dateStr,
                $dayOfWeek,
                $startTime,
                $endTime
            );
            $totalPrice = round(($hourlyRate * $durationMinutes) / 60, 2);

            // 10. Create Booking
            return Booking::create([
                'court_id' => $court->id,
                'user_id' => $isManual ? null : $user->id,
                'customer_name' => $isManual ? $extraParams['customer_name'] : $user->name,
                'customer_phone' => $isManual ? ($extraParams['customer_phone'] ?? null) : $user->phone,
                'is_manual' => $isManual,
                'notes' => $extraParams['notes'] ?? null,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'total_price' => $totalPrice,
                'status' => Booking::STATUS_CONFIRMED,
            ]);
        });
    }

    /**
     * Cancel a booking.
     */
    public function cancelBooking(User $user, Booking $booking): Booking
    {
        if (!$user->isAdmin() && $user->id !== $booking->user_id && $user->id !== $booking->court->venue->user_id) {
            throw ValidationException::withMessages([
                'booking' => ['Você não tem permissão para cancelar este agendamento.'],
            ]);
        }

        if ($booking->status === Booking::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'booking' => ['Este agendamento já foi cancelado.'],
            ]);
        }

        $booking->update([
            'status' => Booking::STATUS_CANCELLED,
        ]);

        return $booking;
    }
}
